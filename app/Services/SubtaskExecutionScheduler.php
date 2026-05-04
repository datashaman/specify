<?php

namespace App\Services;

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteSubtaskJob;
use App\Models\AgentRun;
use App\Models\PlanApproval;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Executors\ExecutorFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Schedules Subtask execution and advances the Subtask -> Task -> Plan -> Story cascade.
 */
class SubtaskExecutionScheduler
{
    public function __construct(private ExecutorFactory $executors) {}

    /**
     * Queue execution for the given Subtask.
     *
     * In single-driver mode this creates one AgentRun. In race mode it
     * creates one sibling AgentRun per configured driver and returns the
     * first sibling for the caller's convenience.
     */
    public function dispatch(Subtask $subtask, ?PlanApproval $approval = null, ?Repo $repo = null): AgentRun
    {
        $repo ??= $subtask->task?->plan?->story?->feature?->project?->primaryRepo();

        $race = $this->executors->raceDrivers();
        if ($race === []) {
            return $this->dispatchDriverRun($subtask, $approval, $repo, $this->executors->defaultDriver(), null);
        }

        $first = null;
        foreach ($race as $driver) {
            $run = $this->dispatchDriverRun($subtask, $approval, $repo, $driver, $driver);
            $first ??= $run;
        }

        return $first;
    }

    /**
     * Queue a retry for one driver against an existing Subtask.
     */
    public function dispatchRetry(
        Subtask $subtask,
        ?PlanApproval $approval,
        ?Repo $repo,
        ?string $driver,
        int $retryOfId,
    ): AgentRun {
        $resolvedDriver = $driver ?? $this->executors->defaultDriver();
        $branchSuffix = in_array($resolvedDriver, $this->executors->raceDrivers(), true) ? $resolvedDriver : null;

        return $this->dispatchDriverRun($subtask, $approval, $repo, $resolvedDriver, $branchSuffix, $retryOfId);
    }

    /**
     * Begin executing an Approved Story by dispatching every actionable subtask.
     *
     * @throws RuntimeException When the Story or current Plan is not ready for execution.
     */
    public function startStory(Story $story, ?PlanApproval $approval = null): void
    {
        if (in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true)) {
            return;
        }

        if ($story->status !== StoryStatus::Approved) {
            throw new RuntimeException('Story must be Approved before execution starts.');
        }

        $currentPlan = $story->currentPlan;
        if (! $currentPlan) {
            throw new RuntimeException('Story must have a current plan before execution starts.');
        }
        if (! $currentPlan->isApproved()) {
            throw new RuntimeException('Current plan must be Approved before execution starts.');
        }

        $approval ??= PlanApproval::query()
            ->where('plan_id', $currentPlan->getKey())
            ->where('plan_revision', $currentPlan->revision ?? 1)
            ->where('decision', ApprovalDecision::Approve->value)
            ->latest('created_at')
            ->first();

        DB::transaction(function () use ($story, $approval) {
            foreach ($this->nextActionableSubtasks($story) as $subtask) {
                if ($subtask->agentRuns()->active()->exists()) {
                    continue;
                }

                $this->dispatch($subtask, $approval);
            }
        });
    }

    /**
     * Subtasks ready to run: parent task dependencies are Done and lower-position siblings are Done.
     *
     * @return Collection<int, Subtask>
     */
    public function nextActionableSubtasks(Story $story): Collection
    {
        return $story->currentPlanTasks()
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

    /**
     * Decide whether a terminal Execute run completes, blocks, or waits on its Subtask.
     */
    public function finalizeSubtaskFromRun(AgentRun $run): void
    {
        if (in_array($run->kind, [AgentRunKind::RespondToReview, AgentRunKind::ResolveConflicts], true)) {
            return;
        }

        DB::transaction(function () use ($run) {
            $subtask = Subtask::query()->whereKey($run->runnable_id)->lockForUpdate()->first();
            if (! $subtask) {
                return;
            }

            $siblings = AgentRun::query()
                ->where('runnable_type', $run->runnable_type)
                ->where('runnable_id', $subtask->getKey())
                ->where('kind', AgentRunKind::Execute->value)
                ->get();

            if ($siblings->contains(fn (AgentRun $r) => $r->isActive())) {
                return;
            }

            $anySucceeded = $siblings->contains(fn (AgentRun $r) => $r->status === AgentRunStatus::Succeeded);

            if ($anySucceeded) {
                $subtask->forceFill(['status' => TaskStatus::Done->value])->save();
                $this->advanceFromSubtask($subtask->fresh(), $run->authorizingApproval);

                return;
            }

            $subtask->forceFill(['status' => TaskStatus::Blocked->value])->save();
        });
    }

    private function dispatchDriverRun(
        Subtask $subtask,
        ?PlanApproval $approval,
        ?Repo $repo,
        string $driver,
        ?string $branchSuffix,
        ?int $retryOfId = null,
    ): AgentRun {
        $run = AgentRun::create([
            'runnable_type' => $subtask->getMorphClass(),
            'runnable_id' => $subtask->getKey(),
            'repo_id' => $repo?->getKey(),
            'working_branch' => $this->workingBranchFor($subtask, $branchSuffix),
            'executor_driver' => $driver,
            'authorizing_approval_type' => $approval?->getMorphClass(),
            'authorizing_approval_id' => $approval?->getKey(),
            'status' => AgentRunStatus::Queued,
            'retry_of_id' => $retryOfId,
        ]);

        ExecuteSubtaskJob::dispatch($run->getKey());

        return $run;
    }

    private function workingBranchFor(Subtask $subtask, ?string $driverSuffix): string
    {
        $story = $subtask->task?->plan?->story;
        $feature = $story?->feature;

        $featureSlug = $feature?->slug ?? 'feature-'.($feature?->getKey() ?? 'orphan');
        $storySlug = $story?->slug ?? 'story-'.($story?->getKey() ?? 'orphan');
        $branch = "specify/{$featureSlug}/{$storySlug}";

        if ($driverSuffix !== null && $driverSuffix !== '') {
            $branch .= '-by-'.$driverSuffix;
        }

        return $branch;
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

        $story = $task->plan->story;
        if (! $story) {
            return;
        }

        $remainingTasks = $story->currentPlanTasks()->where('tasks.status', '!=', TaskStatus::Done->value)->count();
        if ($remainingTasks === 0) {
            if ($story->currentPlan) {
                $story->currentPlan->forceFill(['status' => PlanStatus::Done->value])->save();
            }
            $story->forceFill(['status' => StoryStatus::Done->value])->save();

            return;
        }

        $approval = $authorizingApproval instanceof PlanApproval ? $authorizingApproval : null;

        foreach ($this->nextActionableSubtasks($story->fresh()) as $next) {
            if ($next->agentRuns()->active()->exists()) {
                continue;
            }

            $this->dispatch($next, $approval);
        }
    }
}
