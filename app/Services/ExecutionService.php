<?php

namespace App\Services;

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteSubtaskJob;
use App\Jobs\GenerateTasksJob;
use App\Jobs\OpenPullRequestJob;
use App\Jobs\RespondToPrReviewJob;
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

    /**
     * Create a `RespondToReview` AgentRun for the given originating Execute
     * run (if the repo opted in and the per-PR cycle cap isn't hit), queue
     * the matching `RespondToPrReviewJob`, and return a result describing
     * what happened (ADR-0008). All decisions happen inside a transaction
     * with `lockForUpdate` on the Repo row so two webhook events arriving
     * concurrently can't both observe under-cap and double-dispatch.
     *
     * Possible result `status` values:
     *   - 'dispatched' — new run + job queued
     *   - 'review_response_disabled' — repo flag is off
     *   - 'max_cycles_reached' — count of existing RespondToReview runs ≥ cap
     *
     * @return array{status: string, run?: AgentRun, cycle?: int, cycles?: int}
     */
    public function dispatchReviewResponse(Repo $repo, AgentRun $originRun, int $pullRequestNumber): array
    {
        return DB::transaction(function () use ($repo, $originRun, $pullRequestNumber) {
            $repo = Repo::query()->whereKey($repo->getKey())->lockForUpdate()->first() ?? $repo;

            if (! $repo->review_response_enabled) {
                return ['status' => 'review_response_disabled'];
            }

            $cycles = AgentRun::query()
                ->where('repo_id', $repo->getKey())
                ->where('kind', AgentRunKind::RespondToReview->value)
                ->whereJsonContains('output->pull_request_number', $pullRequestNumber)
                ->count();

            if ($cycles >= ($repo->max_review_response_cycles ?? 3)) {
                return ['status' => 'max_cycles_reached', 'cycles' => $cycles];
            }

            $run = AgentRun::create([
                'runnable_type' => $originRun->runnable_type,
                'runnable_id' => $originRun->runnable_id,
                'repo_id' => $repo->getKey(),
                'working_branch' => $originRun->working_branch,
                'executor_driver' => $originRun->executor_driver,
                'kind' => AgentRunKind::RespondToReview->value,
                'status' => AgentRunStatus::Queued->value,
                'output' => [
                    'pull_request_number' => $pullRequestNumber,
                    'origin_run_id' => $originRun->getKey(),
                ],
            ]);

            RespondToPrReviewJob::dispatch($run->getKey());

            return ['status' => 'dispatched', 'run' => $run, 'cycle' => $cycles + 1];
        });
    }

    private function createAndDispatchRun(Subtask $subtask, ?StoryApproval $approval, ?Repo $repo, string $driver, ?string $branchSuffix, ?int $retryOfId = null): AgentRun
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
            'retry_of_id' => $retryOfId,
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
                $hasOpenRun = $subtask->agentRuns()->active()->exists();
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

    /**
     * Transition an AgentRun to Running and stamp `started_at`.
     *
     * Conditional on the persisted status still being Queued — closes the
     * queued-cancel race (ADR-0010): if `cancelRun()` flipped the row to
     * Cancelled between the worker's `findOrFail` and this call, the
     * UPDATE matches zero rows and we return false so the caller bails.
     */
    public function markRunning(AgentRun $run): bool
    {
        $affected = AgentRun::query()
            ->whereKey($run->getKey())
            ->where('status', AgentRunStatus::Queued->value)
            ->update([
                'status' => AgentRunStatus::Running->value,
                'started_at' => now(),
            ]);

        if ($affected === 0) {
            return false;
        }

        $run->forceFill([
            'status' => AgentRunStatus::Running->value,
            'started_at' => now(),
        ])->syncOriginal();

        return true;
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
     *
     * Idempotent: the UPDATE is conditional on a non-terminal status. A late
     * caller (e.g. the queue's `failed()` callback after the catch block has
     * already marked the row Failed, or after a cooperative Cancelled) sees
     * zero rows affected and bails — terminal stays terminal.
     */
    public function markFailed(AgentRun $run, string $error): void
    {
        $affected = AgentRun::query()
            ->whereKey($run->getKey())
            ->whereIn('status', [
                AgentRunStatus::Queued->value,
                AgentRunStatus::Running->value,
            ])
            ->update([
                'status' => AgentRunStatus::Failed->value,
                'error_message' => $error,
                'finished_at' => now(),
            ]);

        if ($affected === 0) {
            return;
        }

        $run->forceFill([
            'status' => AgentRunStatus::Failed->value,
            'error_message' => $error,
            'finished_at' => now(),
        ])->syncOriginal();

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
     * Mark an AgentRun Cancelled — cooperative cancel observed at a pipeline
     * phase boundary (ADR-0010). Failure-class for cascade purposes; siblings
     * count as losers.
     */
    public function markCancelled(AgentRun $run, ?string $reason = null): void
    {
        $run->forceFill([
            'status' => AgentRunStatus::Cancelled->value,
            'error_message' => $reason ?? 'Cancelled by user.',
            'finished_at' => now(),
        ])->save();

        if ($run->runnable_type === Subtask::class) {
            $this->finalizeSubtaskFromRun($run);
        }
    }

    /**
     * Request cooperative cancellation of an AgentRun (ADR-0010).
     *
     * Queued runs short-circuit straight to Cancelled (the job hasn't
     * started, so there's nothing to cooperate with). Running runs have the
     * `cancel_requested` flag set; the pipeline's poll points observe it
     * between phases and transition to Cancelled. Terminal runs are no-ops.
     *
     * Returns true if anything changed.
     */
    public function cancelRun(AgentRun $run, ?string $reason = null): bool
    {
        if ($run->isTerminal()) {
            return false;
        }

        // Set the audit flag in both branches: a Queued run that flips
        // straight to Cancelled still records *that the cancel was
        // requested*, matching ADR-0010's "cancel_requested is part of the
        // audit trail" claim. Without this, a Queued cancel would leave a
        // Cancelled run with cancel_requested=false and reviewers couldn't
        // tell whether the run terminated cooperatively or because the
        // worker never picked it up.
        $run->forceFill(['cancel_requested' => true])->save();

        if ($run->status === AgentRunStatus::Queued) {
            $this->markCancelled($run, $reason);
        }

        return true;
    }

    /**
     * Retry a Subtask Execute run by dispatching a fresh sibling AgentRun
     * that points at the prior run via `retry_of_id` (ADR-0010).
     *
     * Authorization re-resolves through the current StoryApproval — if the
     * Story has been edited since (revision bumped → approval reset), the
     * retry binds to the current approving approval. Throws when the Story
     * is not Approved (revoked / changes-requested / superseded).
     *
     * Race mode: this retries one driver. The driver defaults to the prior
     * run's `executor_driver` so a single-sibling retry is just "do that
     * one again". Pass `$driver` to override.
     */
    public function retrySubtaskExecution(Subtask $subtask, AgentRun $fromRun, ?string $driver = null): AgentRun
    {
        if ($fromRun->runnable_type !== Subtask::class || (int) $fromRun->runnable_id !== (int) $subtask->getKey()) {
            throw new RuntimeException('Run does not belong to this subtask.');
        }
        if ($fromRun->kind === AgentRunKind::RespondToReview) {
            throw new RuntimeException('Review-response runs are not retryable; new review events re-fire automatically.');
        }
        if (! $fromRun->isTerminal()) {
            throw new RuntimeException('Cannot retry a run that is still in flight.');
        }
        // Service-level invariant: only failure-class runs can be retried.
        // The UI hides Retry on Succeeded runs already, but a non-UI
        // caller (MCP tool, internal automation) without this guard could
        // re-dispatch an already-successful Subtask and double-open PRs.
        if (! $fromRun->status->isFailure()) {
            throw new RuntimeException('Only failed / cancelled / aborted runs can be retried.');
        }

        $story = $subtask->task?->story;
        if (! $story || $story->status !== StoryStatus::Approved) {
            throw new RuntimeException('Story is not Approved; retry is gated on a current approval.');
        }

        $approval = StoryApproval::query()
            ->where('story_id', $story->getKey())
            ->where('story_revision', $story->revision ?? 1)
            ->where('decision', ApprovalDecision::Approve->value)
            ->latest('created_at')
            ->first();

        $repo = $fromRun->repo ?? $story->feature?->project?->primaryRepo();
        $resolvedDriver = $driver ?? $fromRun->executor_driver ?? $this->executors->defaultDriver();
        $race = $this->executors->raceDrivers();
        $branchSuffix = in_array($resolvedDriver, $race, true) ? $resolvedDriver : null;

        return $this->createAndDispatchRun(
            $subtask,
            $approval,
            $repo,
            $resolvedDriver,
            $branchSuffix,
            $fromRun->getKey(),
        );
    }

    /**
     * Retry the PR-open step for a Succeeded run whose previous open()
     * attempt failed (ADR-0004 — non-fatal — so the run is Succeeded but
     * `pull_request_error` is set and `pull_request_url` is empty).
     *
     * The AgentRun's terminal status is preserved; only its output is
     * updated. See OpenPullRequestJob for idempotency + locking semantics
     * (ADR-0010).
     */
    public function retryPullRequestOpen(AgentRun $run): void
    {
        if ($run->status !== AgentRunStatus::Succeeded) {
            throw new RuntimeException('PR retry only applies to Succeeded runs.');
        }
        $output = (array) $run->output;
        if (! empty($output['pull_request_url'])) {
            throw new RuntimeException('Run already has a pull_request_url; nothing to retry.');
        }

        OpenPullRequestJob::dispatch($run->getKey());
    }

    /**
     * Convenience: cancel every still-open AgentRun for a Subtask. Useful for
     * race mode where one button cancels all sibling drivers (ADR-0010).
     *
     * @return int Number of runs whose state changed.
     */
    public function cancelSubtask(Subtask $subtask, ?string $reason = null): int
    {
        $changed = 0;
        foreach ($subtask->agentRuns()->active()->get() as $run) {
            if ($this->cancelRun($run, $reason)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Cascade gate. Called whenever a Subtask AgentRun reaches a terminal
     * state. Defers if any sibling on the same Subtask is still Queued or
     * Running. Once all siblings have terminated:
     *
     *   - any Succeeded   → Subtask Done, advance the cascade
     *   - none Succeeded  → Subtask Blocked, no cascade
     *
     * Concurrency: serialised on the parent Subtask row via
     * `lockForUpdate` inside a transaction. Without the lock, two terminal
     * callbacks arriving simultaneously on the last two siblings could both
     * pass the "no still-open" check, both call `advanceFromSubtask`, and
     * both dispatch the *next* Subtask before either AgentRun was committed
     * — duplicating downstream runs. The lock makes the cascade decision
     * (read siblings → write Subtask status → dispatch next) atomic per
     * Subtask.
     */
    private function finalizeSubtaskFromRun(AgentRun $run): void
    {
        // ADR-0008: review-response runs do not decide cascade — the Subtask
        // is already Done by the time review feedback arrives, and the
        // RespondToReview run only pushes a `fix(review):` commit on its
        // open PR. Returning here keeps the cascade gate purely about
        // Execute-kind run terminations.
        if ($run->kind === AgentRunKind::RespondToReview) {
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
            if ($next->agentRuns()->active()->exists()) {
                continue;
            }
            $this->dispatchSubtaskExecution($next, $approval);
        }
    }
}
