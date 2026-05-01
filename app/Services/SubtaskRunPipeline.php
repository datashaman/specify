<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use App\Services\Executors\Executor;
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
    ) {}

    public function run(AgentRun $agentRun): SubtaskRunOutcome
    {
        $subtask = $agentRun->runnable;
        if (! $subtask instanceof Subtask) {
            throw new RuntimeException('Subtask for AgentRun not found.');
        }

        $logCtx = $this->logContext($agentRun, $subtask);
        Log::info('specify.subtask.run.starting', $logCtx + ['subtask_name' => $subtask->name]);

        $repo = $agentRun->repo;
        $workingDir = null;

        if ($this->executor->needsWorkingDirectory()) {
            if ($repo === null) {
                throw new RuntimeException('Executor requires a repo, but none is bound to this AgentRun.');
            }

            $branch = $this->branchFor($agentRun);
            $workingDir = $this->workspace->prepare($repo, $agentRun);
            $this->workspace->checkoutBranch($workingDir, $branch, baseBranch: $repo->default_branch);
            Log::info('specify.subtask.workspace.ready', $logCtx + ['working_dir' => $workingDir]);
        }

        $result = $this->executor->execute($subtask, $workingDir, $repo, $agentRun->working_branch);
        $output = $result->toArray();

        if ($workingDir === null) {
            $diff = $result->filesChanged === [] ? null : implode("\n", $result->filesChanged);
            $output['commit_sha'] = null;
            Log::info('specify.subtask.run.succeeded', $logCtx + ['commit_sha' => null]);

            return SubtaskRunOutcome::succeeded($output, $diff);
        }

        $commitSha = $this->workspace->commit($workingDir, $result->commitMessage ?: 'specify: agent run');
        $diff = $this->workspace->diff($workingDir);

        Log::info('specify.subtask.commit', $logCtx + [
            'commit_sha' => $commitSha,
            'diff_bytes' => strlen((string) $diff),
        ]);

        if ($commitSha === null) {
            $summary = trim($result->summary);
            $reason = 'Agent produced no diff. The subtask was not executed.';
            if ($summary !== '') {
                $reason .= ' Agent summary: '.$summary;
            }
            Log::warning('specify.subtask.no_diff', $logCtx + ['summary' => $summary]);

            return SubtaskRunOutcome::noDiff($reason);
        }

        $output['commit_sha'] = $commitSha;

        if (config('specify.workspace.push_after_commit', true)) {
            $branch = $this->branchFor($agentRun);
            $this->workspace->push($workingDir, $branch);
            $output['pushed'] = true;
            Log::info('specify.subtask.push', $logCtx);

            if (config('specify.workspace.open_pr_after_push', true) && $repo !== null) {
                $prResult = $this->openPullRequest($repo, $subtask, $branch, $output);
                $output = array_merge($output, $prResult);

                if (isset($prResult['pull_request_error'])) {
                    Log::warning('specify.subtask.pr_failed', $logCtx + ['error' => $prResult['pull_request_error']]);

                    return SubtaskRunOutcome::pullRequestFailed(
                        $output,
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
    private function openPullRequest(Repo $repo, Subtask $subtask, string $branch, array $output): array
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

    private function branchFor(AgentRun $agentRun): string
    {
        return $agentRun->working_branch ?? 'specify/run-'.$agentRun->getKey();
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
