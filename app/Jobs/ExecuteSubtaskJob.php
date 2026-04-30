<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Subtask;
use App\Services\ExecutionService;
use App\Services\Executors\Executor;
use App\Services\PullRequests\PullRequestManager;
use App\Services\WorkspaceRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteSubtaskJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(
        ExecutionService $execution,
        Executor $executor,
        WorkspaceRunner $workspace,
        PullRequestManager $pullRequests,
    ): void {
        $run = AgentRun::findOrFail($this->agentRunId);
        $subtask = $run->runnable;

        if (! $subtask instanceof Subtask) {
            $execution->markFailed($run, 'Subtask for AgentRun not found.');

            return;
        }

        $execution->markRunning($run);

        $workingDir = null;
        $commitSha = null;
        $diff = null;

        $logCtx = [
            'run_id' => $run->getKey(),
            'subtask_id' => $subtask->getKey(),
            'story_id' => $subtask->task?->story_id,
            'branch' => $run->working_branch,
        ];

        Log::info('specify.subtask.run.starting', $logCtx + [
            'subtask_name' => $subtask->name,
        ]);

        try {
            $repo = $run->repo;

            if ($executor->needsWorkingDirectory()) {
                if ($repo === null) {
                    throw new \RuntimeException('Executor requires a repo, but none is bound to this AgentRun.');
                }

                $workingDir = $workspace->prepare($repo, $run);
                $workspace->checkoutBranch($workingDir, $run->working_branch ?? 'specify/run-'.$run->getKey(), baseBranch: $repo->default_branch);
                Log::info('specify.subtask.workspace.ready', $logCtx + ['working_dir' => $workingDir]);
            }

            $output = $executor->execute($subtask, $workingDir, $repo, $run->working_branch);

            if ($workingDir !== null) {
                $commitSha = $workspace->commit($workingDir, $output['commit_message'] ?: 'specify: agent run');
                $diff = $workspace->diff($workingDir);

                Log::info('specify.subtask.commit', $logCtx + [
                    'commit_sha' => $commitSha,
                    'diff_bytes' => strlen((string) $diff),
                ]);

                if ($commitSha === null) {
                    $summary = trim((string) ($output['summary'] ?? ''));
                    $reason = 'Agent produced no diff. The subtask was not executed.';
                    if ($summary !== '') {
                        $reason .= ' Agent summary: '.$summary;
                    }
                    Log::warning('specify.subtask.no_diff', $logCtx + ['summary' => $summary]);
                    $execution->markFailed($run, $reason);

                    return;
                }

                if (config('specify.workspace.push_after_commit', true)) {
                    $branch = $run->working_branch ?? 'specify/run-'.$run->getKey();
                    $workspace->push($workingDir, $branch);
                    $output['pushed'] = true;
                    Log::info('specify.subtask.push', $logCtx);

                    if (config('specify.workspace.open_pr_after_push', true)) {
                        $prResult = $this->openPullRequest($pullRequests, $run, $subtask, $branch, $output);
                        $output = array_merge($output, $prResult);

                        if (isset($prResult['pull_request_error'])) {
                            Log::warning('specify.subtask.pr_failed', $logCtx + ['error' => $prResult['pull_request_error']]);
                            $output['commit_sha'] = $commitSha;
                            $execution->markFailed($run, 'Pull request creation failed: '.$prResult['pull_request_error']);

                            return;
                        }

                        if (isset($prResult['pull_request_url'])) {
                            Log::info('specify.subtask.pr_opened', $logCtx + ['url' => $prResult['pull_request_url']]);
                        }
                    }
                }
            } else {
                $diff = $output['files_changed'] === [] ? null : implode("\n", $output['files_changed']);
            }

            $output['commit_sha'] = $commitSha;
            Log::info('specify.subtask.run.succeeded', $logCtx + ['commit_sha' => $commitSha]);
            $execution->markSucceeded($run, $output, $diff);
        } catch (Throwable $e) {
            Log::error('specify.subtask.run.failed', $logCtx + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $execution->markFailed($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function openPullRequest(
        PullRequestManager $manager,
        AgentRun $run,
        Subtask $subtask,
        string $branch,
        array $output,
    ): array {
        $repo = $run->repo;
        if ($repo === null) {
            return [];
        }

        $provider = $manager->for($repo);
        if ($provider === null) {
            return ['pull_request_skipped' => 'unsupported_provider'];
        }

        try {
            $pr = $provider->createPullRequest(
                repo: $repo,
                head: $branch,
                base: $repo->default_branch,
                title: 'Specify: '.$subtask->name,
                body: trim((string) ($output['summary'] ?? '')),
            );

            return [
                'pull_request_url' => $pr['url'],
                'pull_request_number' => $pr['number'],
            ];
        } catch (Throwable $e) {
            return ['pull_request_error' => $e->getMessage()];
        }
    }
}
