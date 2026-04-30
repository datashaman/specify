<?php

namespace App\Services;

use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteSubtaskJob;
use App\Jobs\GenerateTasksJob;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExecutionService
{
    public function dispatchTaskGeneration(Story $story, ?StoryApproval $approval = null): AgentRun
    {
        $run = AgentRun::create([
            'runnable_type' => $story->getMorphClass(),
            'runnable_id' => $story->getKey(),
            'authorizing_approval_type' => $approval?->getMorphClass(),
            'authorizing_approval_id' => $approval?->getKey(),
            'status' => AgentRunStatus::Queued,
        ]);

        GenerateTasksJob::dispatch($run->getKey());

        return $run;
    }

    public function dispatchSubtaskExecution(Subtask $subtask, ?StoryApproval $approval = null, ?Repo $repo = null): AgentRun
    {
        $repo ??= $subtask->task?->story?->feature?->project?->primaryRepo();

        $run = AgentRun::create([
            'runnable_type' => $subtask->getMorphClass(),
            'runnable_id' => $subtask->getKey(),
            'repo_id' => $repo?->getKey(),
            'working_branch' => $this->workingBranchFor($subtask),
            'authorizing_approval_type' => $approval?->getMorphClass(),
            'authorizing_approval_id' => $approval?->getKey(),
            'status' => AgentRunStatus::Queued,
        ]);

        ExecuteSubtaskJob::dispatch($run->getKey());

        return $run;
    }

    private function workingBranchFor(Subtask $subtask): string
    {
        $story = $subtask->task?->story;
        $storyId = $story?->getKey() ?? 'orphan';

        return "specify/story-{$storyId}";
    }

    public function startStoryExecution(Story $story, ?StoryApproval $approval = null): void
    {
        if (in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true)) {
            return;
        }

        if ($story->status !== StoryStatus::Approved) {
            throw new RuntimeException('Story must be Approved before execution starts.');
        }

        DB::transaction(function () use ($story, $approval) {
            foreach ($this->nextActionableSubtasks($story) as $subtask) {
                $hasOpenRun = $subtask->agentRuns()
                    ->whereIn('status', [AgentRunStatus::Queued->value, AgentRunStatus::Running->value])
                    ->exists();
                if ($hasOpenRun) {
                    continue;
                }
                $this->dispatchSubtaskExecution($subtask, $approval);
            }
        });
    }

    /**
     * Subtasks ready to run: parent task's task-deps all Done AND
     * in-task siblings at lower positions all Done.
     *
     * @return Collection<int, Subtask>
     */
    public function nextActionableSubtasks(Story $story): Collection
    {
        return $story->tasks()
            ->with(['dependencies:id,status', 'subtasks:id,task_id,position,status'])
            ->get()
            ->filter(fn (Task $task) => $task->dependencies->every(fn (Task $d) => $d->status === TaskStatus::Done))
            ->flatMap(function (Task $task) {
                $sorted = $task->subtasks->sortBy('position')->values();
                $next = $sorted->first(fn (Subtask $s) => $s->status !== TaskStatus::Done);
                if (! $next || $next->status !== TaskStatus::Pending) {
                    return [];
                }

                return [$next];
            })
            ->values();
    }

    public function markRunning(AgentRun $run): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Running->value,
            'started_at' => now(),
        ])->save();
    }

    public function markSucceeded(AgentRun $run, ?array $output = null, ?string $diff = null): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Succeeded->value,
            'output' => $output,
            'diff' => $diff,
            'finished_at' => now(),
        ])->save();

        if ($run->runnable_type === Subtask::class) {
            $subtask = Subtask::find($run->runnable_id);
            if ($subtask) {
                $subtask->forceFill(['status' => TaskStatus::Done->value])->save();
                $this->advanceFromSubtask($subtask->fresh(), $run->authorizingApproval);
            }
        }
    }

    public function markFailed(AgentRun $run, string $error): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Failed->value,
            'error_message' => $error,
            'finished_at' => now(),
        ])->save();

        if ($run->runnable_type === Subtask::class) {
            $subtask = Subtask::find($run->runnable_id);
            $subtask?->forceFill(['status' => TaskStatus::Blocked->value])->save();
        }
    }

    public function markAborted(AgentRun $run, ?string $reason = null): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Aborted->value,
            'error_message' => $reason,
            'finished_at' => now(),
        ])->save();
    }

    private function advanceFromSubtask(?Subtask $subtask, $authorizingApproval): void
    {
        if (! $subtask) {
            return;
        }

        $task = $subtask->task;
        if (! $task) {
            return;
        }

        $remainingInTask = $task->subtasks()->where('status', '!=', TaskStatus::Done->value)->count();
        if ($remainingInTask === 0) {
            $task->forceFill(['status' => TaskStatus::Done->value])->save();
        }

        $story = $task->story;
        if (! $story) {
            return;
        }

        $remainingTasks = $story->tasks()->where('status', '!=', TaskStatus::Done->value)->count();
        if ($remainingTasks === 0) {
            $story->forceFill(['status' => StoryStatus::Done->value])->save();

            return;
        }

        $approval = $authorizingApproval instanceof StoryApproval ? $authorizingApproval : null;

        foreach ($this->nextActionableSubtasks($story->fresh()) as $next) {
            $hasOpenRun = $next->agentRuns()
                ->whereIn('status', [AgentRunStatus::Queued->value, AgentRunStatus::Running->value])
                ->exists();
            if ($hasOpenRun) {
                continue;
            }
            $this->dispatchSubtaskExecution($next, $approval);
        }
    }
}
