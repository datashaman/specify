<?php

namespace App\Jobs;

use App\Ai\Agents\ReviewResponder;
use App\Enums\AgentRunKind;
use App\Models\AgentRun;
use App\Models\Subtask;
use App\Services\ExecutionService;
use App\Services\Reviews\ReviewCommentsFetcher;
use App\Services\WorkspaceRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Queue job for a `RespondToReview` AgentRun (ADR-0008).
 *
 * Loads the run, prepares the working dir and checks out the PR's head
 * branch, fetches the open review comments via the GitHub API, hands off
 * to the `ReviewResponder` agent, commits + pushes a `fix(review):` change.
 * Does not open a PR — the PR is already open.
 */
class RespondToPrReviewJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(
        ExecutionService $execution,
        WorkspaceRunner $workspace,
        ReviewCommentsFetcher $fetcher,
    ): void {
        $run = AgentRun::findOrFail($this->agentRunId);

        if ($run->kind !== AgentRunKind::RespondToReview) {
            $execution->markFailed($run, 'RespondToPrReviewJob dispatched for a non-review-response AgentRun.');

            return;
        }

        $subtask = $run->runnable;
        $repo = $run->repo;
        $prNumber = (int) ($run->output['pull_request_number'] ?? 0);
        $branch = $run->working_branch;

        if (! $subtask instanceof Subtask || $repo === null || $prNumber === 0 || $branch === null || $branch === '') {
            $execution->markFailed($run, 'RespondToPrReviewJob: incomplete run state (subtask/repo/PR number/branch missing).');

            return;
        }

        $execution->markRunning($run);

        $logCtx = [
            'run_id' => $run->getKey(),
            'subtask_id' => $subtask->getKey(),
            'pull_request_number' => $prNumber,
            'branch' => $branch,
        ];

        try {
            $workingDir = $workspace->prepare($repo, $run);
            $workspace->checkoutBranch($workingDir, $branch, baseBranch: $repo->default_branch);
            Log::info('specify.review_response.workspace.ready', $logCtx + ['working_dir' => $workingDir]);

            [$reviewSummary, $comments] = $fetcher->fetch($repo, $prNumber);
            Log::info('specify.review_response.comments.fetched', $logCtx + [
                'comment_count' => count($comments),
            ]);

            if ($comments === [] && trim($reviewSummary) === '') {
                $execution->markSucceeded($run, [
                    'pull_request_number' => $prNumber,
                    'no_review_content' => true,
                ], null);
                Log::info('specify.review_response.empty', $logCtx);

                return;
            }

            $agent = new ReviewResponder(
                subtask: $subtask,
                pullRequestNumber: $prNumber,
                reviewSummary: $reviewSummary,
                comments: $comments,
                workingBranch: $branch,
                workingDir: $workingDir,
            );

            $output = $agent->run()->structured();

            $clarifications = (array) ($output['clarifications'] ?? []);
            $summary = trim((string) ($output['summary'] ?? ''));
            $commitMessage = trim((string) ($output['commit_message'] ?? '')) ?: "fix(review): address PR #{$prNumber} review";

            $commitSha = $workspace->commit($workingDir, $commitMessage);
            $diff = $workspace->diff($workingDir);

            $payload = [
                'pull_request_number' => $prNumber,
                'origin_run_id' => (int) ($run->output['origin_run_id'] ?? 0),
                'summary' => $summary,
                'files_changed' => array_values((array) ($output['files_changed'] ?? [])),
                'commit_message' => $commitMessage,
                'commit_sha' => $commitSha,
                'clarifications' => $clarifications,
                'review_comment_count' => count($comments),
            ];

            if ($commitSha === null) {
                Log::info('specify.review_response.no_diff', $logCtx + [
                    'clarifications' => count($clarifications),
                ]);
                $execution->markSucceeded($run, $payload, null);

                return;
            }

            $workspace->push($workingDir, $branch);
            $payload['pushed'] = true;
            Log::info('specify.review_response.pushed', $logCtx + ['commit_sha' => $commitSha]);

            $execution->markSucceeded($run, $payload, $diff);
        } catch (Throwable $e) {
            Log::error('specify.review_response.failed', $logCtx + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $execution->markFailed($run, $e->getMessage());

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
