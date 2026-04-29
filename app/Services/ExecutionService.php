<?php

namespace App\Services;

use App\Enums\AgentRunStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteTaskJob;
use App\Jobs\GeneratePlanJob;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\Repo;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExecutionService
{
    public function dispatchPlanGeneration(Story $story, ?StoryApproval $approval = null): AgentRun
    {
        $run = AgentRun::create([
            'runnable_type' => $story->getMorphClass(),
            'runnable_id' => $story->getKey(),
            'authorizing_approval_type' => $approval?->getMorphClass(),
            'authorizing_approval_id' => $approval?->getKey(),
            'status' => AgentRunStatus::Queued,
        ]);

        GeneratePlanJob::dispatch($run->getKey());

        return $run;
    }

    public function dispatchTaskExecution(Task $task, ?PlanApproval $approval = null, ?Repo $repo = null): AgentRun
    {
        $repo ??= $task->plan?->story?->feature?->project?->primaryRepo();

        $run = AgentRun::create([
            'runnable_type' => $task->getMorphClass(),
            'runnable_id' => $task->getKey(),
            'repo_id' => $repo?->getKey(),
            'working_branch' => $this->workingBranchFor($task),
            'authorizing_approval_type' => $approval?->getMorphClass(),
            'authorizing_approval_id' => $approval?->getKey(),
            'status' => AgentRunStatus::Queued,
        ]);

        ExecuteTaskJob::dispatch($run->getKey());

        return $run;
    }

    private function workingBranchFor(Task $task): string
    {
        $story = $task->plan?->story;
        $storyId = $story?->getKey() ?? 'orphan';
        $version = $task->plan?->version ?? 0;

        return "specify/story-{$storyId}-v{$version}-task-{$task->position}";
    }

    public function startPlanExecution(Plan $plan, ?PlanApproval $approval = null): void
    {
        if (in_array($plan->status, [PlanStatus::Done, PlanStatus::Rejected], true)) {
            return;
        }

        if (! in_array($plan->status, [PlanStatus::Approved, PlanStatus::Executing], true)) {
            throw new RuntimeException('Plan must be Approved before execution starts.');
        }

        DB::transaction(function () use ($plan, $approval) {
            if ($plan->status !== PlanStatus::Executing) {
                $plan->forceFill(['status' => PlanStatus::Executing->value])->save();
            }

            foreach ($plan->nextActionableTasks() as $task) {
                $hasOpenRun = $task->agentRuns()
                    ->whereIn('status', [AgentRunStatus::Queued->value, AgentRunStatus::Running->value])
                    ->exists();
                if ($hasOpenRun) {
                    continue;
                }
                $this->dispatchTaskExecution($task, $approval);
            }
        });
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

        if ($run->runnable_type === Task::class) {
            $task = Task::find($run->runnable_id);
            if ($task) {
                $task->forceFill(['status' => TaskStatus::Done->value])->save();
                $this->advancePlan($task->plan, $run->authorizingApproval);
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

        if ($run->runnable_type === Task::class) {
            $task = Task::find($run->runnable_id);
            $task?->forceFill(['status' => TaskStatus::Blocked->value])->save();
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

    private function advancePlan(?Plan $plan, $authorizingApproval): void
    {
        if (! $plan) {
            return;
        }

        $approval = $authorizingApproval instanceof PlanApproval ? $authorizingApproval : null;

        $remaining = $plan->tasks()->where('status', '!=', TaskStatus::Done->value)->count();

        if ($remaining === 0) {
            $plan->forceFill(['status' => PlanStatus::Done->value])->save();

            return;
        }

        foreach ($plan->nextActionableTasks() as $task) {
            $hasOpenRun = $task->agentRuns()
                ->whereIn('status', [AgentRunStatus::Queued->value, AgentRunStatus::Running->value])
                ->exists();
            if ($hasOpenRun) {
                continue;
            }
            $this->dispatchTaskExecution($task, $approval);
        }
    }
}
