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

/**
 * Orchestrates AgentRun lifecycles for task generation and subtask execution.
 *
 * Each public dispatch* / start* method creates an `AgentRun` row, queues the
 * matching job, and (on completion via `markSucceeded`) advances the cascade
 * down through Subtask → Task → Story status. This class is the only place
 * AgentRun rows should be created.
 */
class ExecutionService
{
    /**
     * Queue a `GenerateTasksJob` for the given Story and return the AgentRun row.
     */
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

    /**
     * Queue an `ExecuteSubtaskJob` for the given Subtask.
     *
     * Resolves the target Repo from the project's primary repo when not supplied,
     * and pre-computes the working branch name (`specify/{feature-slug}/{story-slug}`).
     */
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
        $feature = $story?->feature;

        $featureSlug = $feature?->slug ?? 'feature-'.($feature?->getKey() ?? 'orphan');
        $storySlug = $story?->slug ?? 'story-'.($story?->getKey() ?? 'orphan');

        return "specify/{$featureSlug}/{$storySlug}";
    }

    /**
     * Begin executing an Approved Story by dispatching every actionable subtask.
     *
     * No-op for terminal statuses (Done/Cancelled/Rejected). Skips subtasks
     * that already have a Queued or Running AgentRun, so it is safe to retry.
     *
     * @throws RuntimeException When the Story is not in Approved status.
     */
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

    /** Transition an AgentRun to Running and stamp `started_at`. */
    public function markRunning(AgentRun $run): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Running->value,
            'started_at' => now(),
        ])->save();
    }

    /**
     * Mark an AgentRun Succeeded, persist its output and diff, and cascade.
     *
     * For Subtask runs, marks the Subtask Done and dispatches the next
     * actionable work via `advanceFromSubtask()` — completing all subtasks
     * of a Task marks the Task Done; completing all Tasks marks the Story Done.
     */
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

    /** Mark an AgentRun Failed and move its Subtask (if any) to Blocked. */
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

    /** Mark an AgentRun Aborted (manual cancel) without cascading. */
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
