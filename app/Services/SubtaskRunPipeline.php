<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use App\Services\Context\ContextBuilder;
use App\Services\Executors\Executor;
use App\Services\Executors\ProposedSubtask;
use App\Services\Progress\ProgressEmitter;
use App\Services\PullRequests\PrPayloadBuilder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sequences a single Subtask AgentRun: prepare the workspace, run the
 * executor, commit, push, open a PR. Returns a SubtaskRunOutcome the queue
 * job translates into AgentRun status changes.
 *
 * The pipeline owns step-by-step logging and the workspace/git/PR
 * coordination. The job is responsible only for queue lifecycle (load run,
 * markRunning, mark{Succeeded,Failed} from the outcome, rethrow on
 * exception).
 */
class SubtaskRunPipeline
{
    public function __construct(
        public Executor $executor,
        public WorkspaceRunner $workspace,
        public PlanWriter $planWriter,
        public ContextBuilder $contextBuilder,
    ) {}

    /**
     * Run the full prepare → execute → commit → push → PR sequence for an AgentRun.
     *
     * Returns a `SubtaskRunOutcome` describing how the run terminated; the caller
     * (`ExecuteSubtaskJob`) translates that into AgentRun status changes. PR
     * failures are non-fatal (see ADR-0004) and surface as a distinct outcome.
     *
     * @throws RuntimeException When the AgentRun's runnable is not a Subtask, or
     *                          when the executor needs a working directory but
     *                          no repo is bound to the run.
     */
    public function run(AgentRun $agentRun, ?Executor $override = null): SubtaskRunOutcome
    {
        $subtask = $agentRun->runnable;
        if (! $subtask instanceof Subtask) {
            throw new RuntimeException('Subtask for AgentRun not found.');
        }

        $executor = $override ?? $this->executor;

        $logCtx = $this->logContext($agentRun, $subtask);
        Log::info('specify.subtask.run.starting', $logCtx + [
            'subtask_name' => $subtask->name,
            'executor_driver' => $agentRun->executor_driver,
        ]);

        $emitter = $executor->supportsProgressEvents() ? new ProgressEmitter($agentRun) : null;

        if ($this->cancelObserved($agentRun, 'before_prepare', $logCtx)) {
            return SubtaskRunOutcome::cancelled();
        }

        $repo = $agentRun->repo;
        $workingDir = null;

        if ($executor->needsWorkingDirectory()) {
            if ($repo === null) {
                throw new RuntimeException('Executor requires a repo, but none is bound to this AgentRun.');
            }

            $emitter?->setPhase('prepare');
            $branch = $this->branchFor($agentRun);
            $workingDir = $this->workspace->prepare($repo, $agentRun);
            $this->workspace->checkoutBranch($workingDir, $branch, baseBranch: $repo->default_branch);
            Log::info('specify.subtask.workspace.ready', $logCtx + ['working_dir' => $workingDir]);
        }

        if ($this->cancelObserved($agentRun, 'before_execute', $logCtx)) {
            $this->discardLocalChangesIfPossible($workingDir, $agentRun, $repo, $logCtx);

            return SubtaskRunOutcome::cancelled();
        }

        $contextBrief = $this->contextBuilder->build($subtask, $workingDir, $repo);
        if ($contextBrief !== '') {
            Log::info('specify.subtask.context_brief.built', $logCtx + [
                'bytes' => strlen($contextBrief),
            ]);
            // Persist the brief on the AgentRun row immediately so it
            // survives failure paths — `markFailed` does not touch
            // `output`, so noDiff / pullRequestFailed runs would otherwise
            // lose the brief that the agent saw.
            $agentRun->forceFill([
                'output' => array_merge((array) $agentRun->output, ['context_brief' => $contextBrief]),
            ])->save();
        }

        $emitter?->setPhase('execute');
        $result = $executor->execute($subtask, $workingDir, $repo, $agentRun->working_branch, $contextBrief !== '' ? $contextBrief : null, $emitter, null);

        if ($this->cancelObserved($agentRun, 'after_execute', $logCtx)) {
            $this->discardLocalChangesIfPossible($workingDir, $agentRun, $repo, $logCtx);

            return SubtaskRunOutcome::cancelled();
        }

        $output = $result->toArray();
        if ($contextBrief !== '') {
            $output['context_brief'] = $contextBrief;
        }

        if ($workingDir === null) {
            $diff = $result->filesChanged === [] ? null : implode("\n", $result->filesChanged);
            $output['commit_sha'] = null;
            $output = $this->applyProposedSubtasks($subtask, $result->proposedSubtasks, $agentRun, $output, $logCtx);
            Log::info('specify.subtask.run.succeeded', $logCtx + ['commit_sha' => null]);

            return SubtaskRunOutcome::succeeded($output, $diff);
        }

        $emitter?->setPhase('commit');
        $commitSha = $this->workspace->commit($workingDir, $result->commitMessage ?: 'specify: agent run');
        $diff = $this->workspace->diff($workingDir);

        Log::info('specify.subtask.commit', $logCtx + [
            'commit_sha' => $commitSha,
            'diff_bytes' => strlen((string) $diff),
        ]);

        if ($commitSha === null) {
            $summary = trim($result->summary);

            // ADR-0007: agent may declare the spec already satisfied on the
            // working branch. Honour the claim only when paired with commit
            // SHAs that exist and are reachable from HEAD.
            if ($result->alreadyComplete) {
                $verified = $this->verifyAlreadyCompleteEvidence(
                    $workingDir,
                    $result->alreadyCompleteEvidence,
                );
                if ($verified !== []) {
                    $output['already_complete'] = true;
                    $output['already_complete_evidence'] = $verified;
                    $output['already_complete_reason'] = $summary;
                    $output['commit_sha'] = null;
                    $output = $this->applyProposedSubtasks($subtask, $result->proposedSubtasks, $agentRun, $output, $logCtx);
                    Log::info('specify.subtask.already_complete', $logCtx + [
                        'summary' => $summary,
                        'evidence' => $verified,
                    ]);

                    return SubtaskRunOutcome::alreadyComplete($output);
                }

                Log::warning('specify.subtask.already_complete.rejected', $logCtx + [
                    'summary' => $summary,
                    'evidence_claimed' => $result->alreadyCompleteEvidence,
                ]);
            }

            $reason = 'Agent produced no diff. The subtask was not executed.';
            if ($result->alreadyComplete) {
                $reason .= ' Agent claimed already_complete but the cited commit SHAs were empty or not all reachable from HEAD; rejected per ADR-0007.';
            }
            if ($summary !== '') {
                $reason .= ' Agent summary: '.$summary;
            }
            Log::warning('specify.subtask.no_diff', $logCtx + ['summary' => $summary]);

            return SubtaskRunOutcome::noDiff($reason);
        }

        $output['commit_sha'] = $commitSha;
        $output = $this->applyProposedSubtasks($subtask, $result->proposedSubtasks, $agentRun, $output, $logCtx);

        if (config('specify.workspace.push_after_commit', true)) {
            if ($this->cancelObserved($agentRun, 'before_push', $logCtx)) {
                $this->discardLocalChangesIfPossible($workingDir, $agentRun, $repo, $logCtx);

                return SubtaskRunOutcome::cancelled();
            }

            $emitter?->setPhase('push');
            $branch = $this->branchFor($agentRun);
            $this->workspace->push($workingDir, $branch);
            $output['pushed'] = true;
            Log::info('specify.subtask.push', $logCtx);

            if (config('specify.workspace.open_pr_after_push', true) && $repo !== null) {
                $emitter?->setPhase('open_pr');
                $prResult = $this->openPullRequest($repo, $subtask, $branch, $output, $agentRun->executor_driver);
                $output = array_merge($output, $prResult);

                if (isset($prResult['pull_request_error'])) {
                    Log::warning('specify.subtask.pr_failed', $logCtx + ['error' => $prResult['pull_request_error']]);

                    return SubtaskRunOutcome::pullRequestFailed(
                        $output,
                        $diff,
                        'Pull request creation failed: '.$prResult['pull_request_error'],
                    );
                }

                if (isset($prResult['pull_request_url'])) {
                    Log::info('specify.subtask.pr_opened', $logCtx + ['url' => $prResult['pull_request_url']]);
                }
            }
        }

        Log::info('specify.subtask.run.succeeded', $logCtx + ['commit_sha' => $commitSha]);

        return SubtaskRunOutcome::succeeded($output, $diff);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function openPullRequest(Repo $repo, Subtask $subtask, string $branch, array $output, ?string $driver): array
    {
        $provider = $repo->pullRequestProvider();
        if ($provider === null) {
            return ['pull_request_skipped' => 'unsupported_provider'];
        }

        try {
            $pr = $provider->createPullRequest(
                repo: $repo,
                head: $branch,
                base: $repo->default_branch,
                title: PrPayloadBuilder::title($subtask, $driver),
                body: PrPayloadBuilder::body($subtask, $output),
            );

            return [
                'pull_request_url' => $pr['url'],
                'pull_request_number' => $pr['number'],
            ];
        } catch (Throwable $e) {
            return ['pull_request_error' => $e->getMessage()];
        }
    }

    private function branchFor(AgentRun $agentRun): string
    {
        return $agentRun->working_branch ?? 'specify/run-'.$agentRun->getKey();
    }

    /**
     * Reset the working branch on cooperative cancel so a future retry
     * doesn't start from a partial commit (ADR-0010). No-op when the
     * executor never needed a working directory.
     */
    private function discardLocalChangesIfPossible(?string $workingDir, AgentRun $agentRun, ?Repo $repo, array $logCtx): void
    {
        if ($workingDir === null || $repo === null) {
            return;
        }

        try {
            $this->workspace->discardLocalChanges(
                $workingDir,
                $this->branchFor($agentRun),
                $repo->default_branch,
            );
        } catch (Throwable $e) {
            // Cleanup is best-effort; surface in logs but never let it
            // turn a clean cancel into a queue exception.
            Log::warning('specify.subtask.cancel_cleanup_failed', $logCtx + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Re-fetch `cancel_requested` from the DB and log when observed (ADR-0010).
     * Re-read because the in-memory model is stale relative to a sibling
     * cancel-request transaction issued while this pipeline was mid-flight.
     */
    private function cancelObserved(AgentRun $agentRun, string $phase, array $logCtx): bool
    {
        $requested = (bool) AgentRun::query()
            ->whereKey($agentRun->getKey())
            ->value('cancel_requested');

        if ($requested) {
            Log::info('specify.subtask.cancel_observed', $logCtx + ['phase' => $phase]);
        }

        return $requested;
    }

    /**
     * Verify that *every* claimed evidence SHA exists on the working branch.
     *
     * Returns the trimmed/deduped input list only when all SHAs are
     * reachable from HEAD; otherwise returns an empty array so the pipeline
     * rejects the alreadyComplete claim and falls through to the no_diff
     * failure path (ADR-0007). All-or-nothing — partial evidence (some real
     * commits + some hallucinated SHAs) is treated the same as no evidence,
     * because a single hallucinated SHA is enough to make the whole claim
     * untrustworthy.
     *
     * @param  list<string>  $shas
     * @return list<string>
     */
    private function verifyAlreadyCompleteEvidence(string $workingDir, array $shas): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn ($sha) => trim((string) $sha), $shas),
            static fn ($sha) => $sha !== '',
        )));

        if ($normalized === []) {
            return [];
        }

        foreach ($normalized as $sha) {
            if (! $this->workspace->isCommitReachableFromHead($workingDir, $sha)) {
                return [];
            }
        }

        return $normalized;
    }

    /**
     * Append-and-merge: persist proposed follow-up Subtasks (ADR-0005) and
     * record them in the AgentRun output so the PR body can render them.
     *
     * Called only on paths that have produced real work — describe-only
     * success or post-commit success — so a `noDiff` outcome cannot strand
     * orphaned Subtasks.
     *
     * @param  list<ProposedSubtask>  $proposed
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $logCtx
     * @return array<string, mixed>
     */
    private function applyProposedSubtasks(Subtask $subtask, array $proposed, AgentRun $agentRun, array $output, array $logCtx): array
    {
        $appended = $this->appendProposedSubtasks($subtask, $proposed, $agentRun);
        if ($appended === []) {
            return $output;
        }

        $output['appended_subtasks'] = array_map(fn ($s) => [
            'id' => $s->getKey(),
            'position' => $s->position,
            'name' => $s->name,
        ], $appended);
        Log::info('specify.subtask.proposed_subtasks.appended', $logCtx + [
            'count' => count($appended),
            'subtask_ids' => array_map(fn ($s) => $s->getKey(), $appended),
        ]);

        return $output;
    }

    /**
     * Append executor-proposed follow-up Subtasks to the parent Task (ADR-0005).
     *
     * Returns the created Subtasks (empty if the executor proposed none or has
     * no parent Task). Failures are non-fatal — appending a follow-up should
     * never tank the run that just succeeded — so we log and continue.
     *
     * @param  list<ProposedSubtask>  $proposed
     * @return list<Subtask>
     */
    private function appendProposedSubtasks(Subtask $subtask, array $proposed, AgentRun $agentRun): array
    {
        if ($proposed === []) {
            return [];
        }

        $task = $subtask->task;
        if ($task === null) {
            Log::warning('specify.subtask.proposed_subtasks.skipped', [
                'run_id' => $agentRun->getKey(),
                'reason' => 'no parent task',
            ]);

            return [];
        }

        try {
            return $this->planWriter->appendProposedSubtasks($task, $proposed, $agentRun);
        } catch (Throwable $e) {
            Log::warning('specify.subtask.proposed_subtasks.failed', [
                'run_id' => $agentRun->getKey(),
                'task_id' => $task->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(AgentRun $agentRun, Subtask $subtask): array
    {
        return [
            'run_id' => $agentRun->getKey(),
            'subtask_id' => $subtask->getKey(),
            'story_id' => $subtask->task?->story_id,
            'branch' => $agentRun->working_branch,
        ];
    }
}
