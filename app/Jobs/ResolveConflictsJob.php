<?php

namespace App\Jobs;

use App\Enums\AgentRunKind;
use App\Models\AgentRun;
use App\Models\Subtask;
use App\Services\ConflictResolutionPrompt;
use App\Services\ExecutionService;
use App\Services\Executors\ExecutorFactory;
use App\Services\Progress\ProgressEmitter;
use App\Services\PullRequests\GithubPullRequestProbe;
use App\Services\WorkspaceRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Queue job for a `ResolveConflicts` AgentRun — merge-conflict repair on the Story's primary PR.
 *
 * Uses the same executor driver as recorded on the AgentRun (`executor_driver` from the
 * originating Execute run), resolved via {@see ExecutorFactory} and `config/specify.php`.
 *
 * Serialised per repo+branch like {@see RespondToPrReviewJob} to avoid concurrent git corruption.
 */
class ResolveConflictsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(
        ExecutionService $execution,
        WorkspaceRunner $workspace,
        GithubPullRequestProbe $github,
        ExecutorFactory $executors,
    ): void {
        $run = AgentRun::findOrFail($this->agentRunId);

        if ($run->kind !== AgentRunKind::ResolveConflicts) {
            $execution->markFailed($run, 'ResolveConflictsJob dispatched for a non-conflict-resolution AgentRun.');

            return;
        }

        $subtask = $run->runnable;
        $repo = $run->repo;
        $prNumber = (int) ($run->output['pull_request_number'] ?? 0);
        $branch = $run->working_branch;
        $base = $repo?->default_branch ?? 'main';

        if (! $subtask instanceof Subtask || $repo === null || $prNumber === 0 || $branch === null || $branch === '') {
            $execution->markFailed($run, 'ResolveConflictsJob: incomplete run state (subtask/repo/PR number/branch missing).');

            return;
        }

        if (! $execution->markRunning($run)) {
            return;
        }

        $driver = $run->executor_driver !== null && $run->executor_driver !== ''
            ? (string) $run->executor_driver
            : $executors->defaultDriver();
        $executor = $executors->make($driver);

        if (! $executor->needsWorkingDirectory()) {
            $execution->markFailed($run, 'Conflict resolution requires an executor with a working directory (configure a cli or laravel-ai driver in specify.executor).');

            return;
        }

        $logCtx = [
            'run_id' => $run->getKey(),
            'subtask_id' => $subtask->getKey(),
            'pull_request_number' => $prNumber,
            'branch' => $branch,
            'executor_driver' => $driver,
        ];

        $lockKey = sprintf('specify:resolve-conflicts:%d:%s', $repo->getKey(), $branch);
        $workingDir = '';

        try {
            Cache::lock($lockKey, 1800)->block(300, function () use ($run, $repo, $branch, $base, $prNumber, $subtask, $execution, $workspace, $github, $executor, $driver, $logCtx, &$workingDir) {
                $workingDir = $workspace->prepare($repo, $run);
                $workspace->checkoutBranch($workingDir, $branch, baseBranch: $base);
                $workspace->fetchOriginBranch($workingDir, $base);

                Log::info('specify.resolve_conflicts.workspace.ready', $logCtx + ['working_dir' => $workingDir]);

                $headBefore = $workspace->currentHeadSha($workingDir);
                $mergeExit = $workspace->mergeNoFfFromOrigin($workingDir, $base);

                if ($mergeExit === 0) {
                    $headAfter = $workspace->currentHeadSha($workingDir);
                    if ($headBefore === $headAfter) {
                        Log::info('specify.resolve_conflicts.merge_up_to_date', $logCtx);
                    } else {
                        $workspace->resetHardToOriginBranch($workingDir, $branch);
                    }

                    $execution->markSucceeded($run, [
                        'pull_request_number' => $prNumber,
                        'origin_run_id' => (int) ($run->output['origin_run_id'] ?? 0),
                        'stale_mergeability' => true,
                        'message' => 'Merge completed without conflicts (GitHub mergeability was stale or branch already matched base).',
                    ], null);

                    return;
                }

                if (! $workspace->hasUnmergedPaths($workingDir)) {
                    $workspace->mergeAbort($workingDir);
                    $execution->markFailed($run, 'Merge failed but git did not report unmerged paths; aborting.');

                    return;
                }

                $unmerged = $workspace->unmergedPaths($workingDir);

                $prompt = ConflictResolutionPrompt::forExecutorContext(
                    $subtask,
                    $repo,
                    $branch,
                    $base,
                    $prNumber,
                    $unmerged,
                );

                $emitter = new ProgressEmitter($run);
                $emitter->setPhase('execute');

                $result = $executor->execute($subtask, $workingDir, $repo, $branch, null, $emitter, $prompt);

                if ($workspace->hasUnmergedPaths($workingDir)) {
                    throw new RuntimeException('Unmerged paths remain after the executor finished.');
                }

                $commitMessage = "fix: resolve merge conflict with {$base} (PR #{$prNumber})";

                $commitSha = $workspace->commit($workingDir, $commitMessage);
                if ($commitSha === null) {
                    throw new RuntimeException('No commit was created after resolving conflicts.');
                }

                $diff = $workspace->diff($workingDir);

                $workspace->push($workingDir, $branch);

                $summary = trim($result->summary);
                $commentBody = "Specify **conflict resolution** (run #{$run->getKey()}, executor `{$driver}`)\n\n{$summary}\n\nCommit: `{$commitSha}`";
                $github->postIssueComment($repo, $prNumber, $commentBody);

                $payload = [
                    'pull_request_number' => $prNumber,
                    'origin_run_id' => (int) ($run->output['origin_run_id'] ?? 0),
                    'summary' => $summary,
                    'files_changed' => $result->filesChanged,
                    'conflict_resolutions' => [],
                    'commit_message' => $commitMessage,
                    'commit_sha' => $commitSha,
                    'pushed' => true,
                    'executor_driver' => $driver,
                ];
                if ($result->executorLog !== null && $result->executorLog !== '') {
                    $payload['executor_log'] = $result->executorLog;
                }

                Log::info('specify.resolve_conflicts.pushed', $logCtx + ['commit_sha' => $commitSha]);
                $execution->markSucceeded($run, $payload, $diff);
            });
        } catch (Throwable $e) {
            if ($workingDir !== '') {
                try {
                    if ($workspace->hasUnmergedPaths($workingDir)) {
                        $workspace->mergeAbort($workingDir);
                    }
                    $workspace->discardLocalChanges($workingDir, $branch, $base);
                } catch (Throwable) {
                    // best-effort cleanup
                }
            }

            Log::error('specify.resolve_conflicts.failed', $logCtx + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $execution->markFailed($run, $e->getMessage());

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = AgentRun::find($this->agentRunId);
        if ($run === null || $run->isTerminal()) {
            return;
        }

        app(ExecutionService::class)->markFailed(
            $run,
            $exception?->getMessage() ?? 'ResolveConflictsJob failed.',
        );
    }
}
