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
use App\Services\Executors\ExecutorFactory;
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
 *
 * When `executor.race` is non-empty the dispatcher fans out to N siblings —
 * one AgentRun per race driver, each on its own branch. The cascade waits
 * until every sibling has terminated before deciding the Subtask's status:
 * Done if any sibling Succeeded, Blocked otherwise. The reviewer picks the
 * winning PR by merging it; the merge state lives on the PR, not the run.
 */
class ExecutionService
{
    public function __construct(public ExecutorFactory $executors) {}

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
     * Single-driver mode (race=[]) creates one AgentRun on
     * `specify/{feature-slug}/{story-slug}` and returns it.
     *
     * Race mode (`executor.race` non-empty) creates *N* AgentRun siblings on
     * `specify/{feature}/{story}-by-{driver}`, dispatches each, and returns
     * the *first* sibling so the signature stays stable for single-driver
     * callers. Callers that need the full set must query
     * `agent_runs WHERE runnable_id = ?` after dispatch — the cascade gate
     * (`finalizeSubtaskFromRun`) is the authoritative observer of all
     * siblings, not this return value.
     */
    public function dispatchSubtaskExecution(Subtask $subtask, ?StoryApproval $approval = null, ?Repo $repo = null): AgentRun
    {
        $repo ??= $subtask->task?->story?->feature?->project?->primaryRepo();

        $race = $this->executors->raceDrivers();
        if ($race === []) {
            return $this->createAndDispatchRun($subtask, $approval, $repo, $this->executors->defaultDriver(), null);
        }

        $first = null;
        foreach ($race as $driver) {
            $run = $this->createAndDispatchRun($subtask, $approval, $repo, $driver, $driver);
            $first ??= $run;
        }

        return $first;
    }

    private function createAndDispatchRun(Subtask $subtask, ?StoryApproval $approval, ?Repo $repo, string $driver, ?string $branchSuffix): AgentRun
    {
        $run = AgentRun::create([
            'runnable_type' => $subtask->getMorphClass(),
            'runnable_id' => $subtask->getKey(),
            'repo_id' => $repo?->getKey(),
            'working_branch' => $this->workingBranchFor($subtask, $branchSuffix),
            'executor_driver' => $driver,
            'authorizing_approval_type' => $approval?->getMorphClass(),
            'authorizing_approval_id' => $approval?->getKey(),
            'status' => AgentRunStatus::Queued,
        ]);

        ExecuteSubtaskJob::dispatch($run->getKey());

        return $run;
    }

    private function workingBranchFor(Subtask $subtask, ?string $driverSuffix): string
    {
        $story = $subtask->task?->story;
        $feature = $story?->feature;

        $featureSlug = $feature?->slug ?? 'feature-'.($feature?->getKey() ?? 'orphan');
        $storySlug = $story?->slug ?? 'story-'.($story?->getKey() ?? 'orphan');
        $branch = "specify/{$featureSlug}/{$storySlug}";

        if ($driverSuffix !== null && $driverSuffix !== '') {
            $branch .= '-by-'.$driverSuffix;
        }

        return $branch;
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
     * Persist a successful AgentRun and let `finalizeSubtaskFromRun` decide
     * whether the cascade fires now or waits for racing siblings.
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
            $this->finalizeSubtaskFromRun($run);
        }
    }

    /**
     * Persist a failed AgentRun. Subtask status is *not* flipped to Blocked
     * here — `finalizeSubtaskFromRun` decides Done vs Blocked once every
     * racing sibling has terminated.
     */
    public function markFailed(AgentRun $run, string $error): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Failed->value,
            'error_message' => $error,
            'finished_at' => now(),
        ])->save();

        if ($run->runnable_type === Subtask::class) {
            $this->finalizeSubtaskFromRun($run);
        }
    }

    /** Mark an AgentRun Aborted (manual cancel). Aborted siblings count as losers. */
    public function markAborted(AgentRun $run, ?string $reason = null): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Aborted->value,
            'error_message' => $reason,
            'finished_at' => now(),
        ])->save();

        if ($run->runnable_type === Subtask::class) {
            $this->finalizeSubtaskFromRun($run);
        }
    }

    /**
     * Cascade gate. Called whenever a Subtask AgentRun reaches a terminal
     * state. Defers if any sibling on the same Subtask is still Queued or
     * Running. Once all siblings have terminated:
     *
     *   - any Succeeded   → Subtask Done, advance the cascade
     *   - none Succeeded  → Subtask Blocked, no cascade
     *
     * Concurrency: not transactionally locked. Two terminal callbacks
     * arriving simultaneously on the last two siblings *can* both pass the
     * "no still-open" check — that's intentional. The race is safe because
     * (a) `forceFill(['status' => Done])` is idempotent under repeat writes
     * to the same value, and (b) `advanceFromSubtask` re-queries
     * `Queued|Running` siblings on the next Subtask before dispatching, so
     * a double cascade cannot double-dispatch downstream work. If the
     * downstream guard ever changes, this method must move to a row-locked
     * transaction.
     */
    private function finalizeSubtaskFromRun(AgentRun $run): void
    {
        $subtask = Subtask::find($run->runnable_id);
        if (! $subtask) {
            return;
        }

        $siblings = AgentRun::query()
            ->where('runnable_type', Subtask::class)
            ->where('runnable_id', $subtask->getKey())
            ->get();

        $stillOpen = $siblings->contains(fn (AgentRun $r) => in_array(
            $r->status,
            [AgentRunStatus::Queued, AgentRunStatus::Running],
            true,
        ));
        if ($stillOpen) {
            return;
        }

        $anySucceeded = $siblings->contains(fn (AgentRun $r) => $r->status === AgentRunStatus::Succeeded);

        if ($anySucceeded) {
            $subtask->forceFill(['status' => TaskStatus::Done->value])->save();
            $this->advanceFromSubtask($subtask->fresh(), $run->authorizingApproval);

            return;
        }

        $subtask->forceFill(['status' => TaskStatus::Blocked->value])->save();
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
