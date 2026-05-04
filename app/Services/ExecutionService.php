<?php

namespace App\Services;

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Jobs\GenerateTasksJob;
use App\Jobs\OpenPullRequestJob;
use App\Jobs\ResolveConflictsJob;
use App\Jobs\RespondToPrReviewJob;
use App\Models\AgentRun;
use App\Models\PlanApproval;
use App\Models\Repo;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\Subtask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates AgentRun lifecycles.
 *
 * Each public dispatch* / start* method creates an `AgentRun` row, queues the
 * matching job, and records terminal run state. Subtask execution dispatch
 * and cascade advancement live in SubtaskExecutionScheduler.
 *
 * When `executor.race` is non-empty the dispatcher fans out to N siblings —
 * one AgentRun per race driver, each on its own branch. The cascade waits
 * until every sibling has terminated before deciding the Subtask's status:
 * Done if any sibling Succeeded, Blocked otherwise. The reviewer picks the
 * winning PR by merging it; the merge state lives on the PR, not the run.
 */
class ExecutionService
{
    public function __construct(private SubtaskExecutionScheduler $subtasks) {}

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
    public function dispatchSubtaskExecution(Subtask $subtask, ?PlanApproval $approval = null, ?Repo $repo = null): AgentRun
    {
        return $this->subtasks->dispatch($subtask, $approval, $repo);
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

    /**
     * Queue a `ResolveConflicts` AgentRun for the Story's primary GitHub PR when
     * mergeability is false, subject to a per-PR attempt cap.
     *
     * @return array{status: string, run?: AgentRun, cycle?: int, cycles?: int}
     */
    public function dispatchConflictResolution(Story $story): array
    {
        $pr = $story->primaryPullRequest();
        if ($pr === null || ($pr['mergeable'] ?? null) !== false) {
            return ['status' => 'not_applicable'];
        }

        $executeRun = AgentRun::query()->with('repo')->find($pr['run_id'] ?? 0);
        if (! $executeRun instanceof AgentRun || $executeRun->kind !== AgentRunKind::Execute) {
            return ['status' => 'missing_origin_run'];
        }

        $repo = $executeRun->repo;
        if ($repo === null) {
            return ['status' => 'missing_repo'];
        }

        $prNumber = (int) ($pr['number'] ?? 0);
        if ($prNumber === 0) {
            return ['status' => 'missing_pr_number'];
        }

        $max = (int) config('specify.conflict_resolution.max_cycles_per_pr', 3);

        return DB::transaction(function () use ($repo, $executeRun, $prNumber, $max) {
            $repo = Repo::query()->whereKey($repo->getKey())->lockForUpdate()->first() ?? $repo;

            $cycles = AgentRun::query()
                ->where('repo_id', $repo->getKey())
                ->where('kind', AgentRunKind::ResolveConflicts->value)
                ->whereJsonContains('output->pull_request_number', $prNumber)
                ->count();

            if ($cycles >= $max) {
                return ['status' => 'max_cycles_reached', 'cycles' => $cycles];
            }

            $run = AgentRun::create([
                'runnable_type' => $executeRun->runnable_type,
                'runnable_id' => $executeRun->runnable_id,
                'repo_id' => $repo->getKey(),
                'working_branch' => $executeRun->working_branch,
                'executor_driver' => $executeRun->executor_driver,
                'kind' => AgentRunKind::ResolveConflicts->value,
                'status' => AgentRunStatus::Queued,
                'output' => [
                    'pull_request_number' => $prNumber,
                    'origin_run_id' => $executeRun->getKey(),
                ],
            ]);

            ResolveConflictsJob::dispatch($run->getKey());

            return ['status' => 'dispatched', 'run' => $run, 'cycle' => $cycles + 1];
        });
    }

    /**
     * Begin executing an Approved Story by dispatching every actionable subtask.
     *
     * No-op for terminal statuses (Done/Cancelled/Rejected). Skips subtasks
     * that already have a Queued or Running AgentRun, so it is safe to retry.
     *
     * @throws RuntimeException When the Story is not in Approved status.
     */
    public function startStoryExecution(Story $story, ?PlanApproval $approval = null): void
    {
        $this->subtasks->startStory($story, $approval);
    }

    /**
     * Subtasks ready to run: parent task's task-deps all Done AND
     * in-task siblings at lower positions all Done.
     *
     * @return Collection<int, Subtask>
     */
    public function nextActionableSubtasks(Story $story): Collection
    {
        return $this->subtasks->nextActionableSubtasks($story);
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
            $this->subtasks->finalizeSubtaskFromRun($run);
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
            $this->subtasks->finalizeSubtaskFromRun($run);
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
            $this->subtasks->finalizeSubtaskFromRun($run);
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
            $this->subtasks->finalizeSubtaskFromRun($run);
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
     * Authorization re-resolves through the current PlanApproval — if the
     * current plan has been edited since (revision bumped → approval reset),
     * the retry binds to the current approving plan approval. Throws when the
     * story or current plan are not currently approved.
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
        if ($fromRun->kind === AgentRunKind::ResolveConflicts) {
            throw new RuntimeException('Conflict-resolution runs are not retryable from the run console.');
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

        $story = $subtask->task?->plan?->story;
        if (! $story || $story->status !== StoryStatus::Approved) {
            throw new RuntimeException('Story is not Approved; retry is gated on a current plan approval.');
        }

        $plan = $story->currentPlan;
        if (! $plan || ! $plan->isApproved()) {
            throw new RuntimeException('Current plan is not Approved; retry is gated on a current plan approval.');
        }

        $approval = PlanApproval::query()
            ->where('plan_id', $plan->getKey())
            ->where('plan_revision', $plan->revision ?? 1)
            ->where('decision', ApprovalDecision::Approve->value)
            ->latest('created_at')
            ->first();

        $repo = $fromRun->repo ?? $story->feature?->project?->primaryRepo();

        return $this->subtasks->dispatchRetry(
            $subtask,
            $approval,
            $repo,
            $driver ?? $fromRun->executor_driver,
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
}
