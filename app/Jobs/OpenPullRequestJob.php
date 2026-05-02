<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Subtask;
use App\Services\PullRequests\PrPayloadBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-0010 Phase C: re-attempt PR-open for a Succeeded AgentRun whose
 * `pull_request_error` is set and `pull_request_url` is empty (ADR-0004:
 * PR-after-push is non-fatal, so the run is Succeeded but PR-less).
 *
 * Idempotent: if `findOpenPullRequest` returns a hit for the run's branch,
 * adopt that PR's URL rather than open a duplicate. Concurrent retries on
 * the same AgentRun are serialised via a `Cache::lock` keyed by run id.
 *
 * The AgentRun's terminal status is preserved — only `output.pull_request_url`
 * and `output.pull_request_error` change.
 */
class OpenPullRequestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(): void
    {
        $run = AgentRun::find($this->agentRunId);
        if ($run === null) {
            return;
        }

        $repo = $run->repo;
        $branch = $run->working_branch;
        if ($repo === null || $branch === null || $branch === '') {
            $this->recordError($run, 'Run has no repo or working_branch; cannot open PR.');

            return;
        }

        if (! $run->runnable instanceof Subtask) {
            $this->recordError($run, 'Run is not a subtask run; cannot open PR.');

            return;
        }

        $provider = $repo->pullRequestProvider();
        if ($provider === null) {
            $this->recordError($run, 'Repo provider does not support pull requests.');

            return;
        }

        $lock = Cache::lock('agent_run.pr_open.'.$run->getKey(), 60);
        if (! $lock->get()) {
            Log::info('specify.subtask.pr_retry.locked', ['run_id' => $run->getKey()]);

            return;
        }

        try {
            $existing = $provider->findOpenPullRequest($repo, $branch);
            if ($existing !== null && ($existing['url'] ?? '') !== '') {
                $this->stampSuccess($run, $existing);

                return;
            }

            $subtask = $run->runnable;
            $output = (array) $run->output;

            try {
                $pr = $provider->createPullRequest(
                    repo: $repo,
                    head: $branch,
                    base: $repo->default_branch,
                    title: PrPayloadBuilder::title($subtask, $run->executor_driver),
                    body: PrPayloadBuilder::body($subtask, $output),
                );
                $this->stampSuccess($run, $pr);
            } catch (Throwable $e) {
                $this->recordError($run, $e->getMessage());
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{url: string, number: int|string, id: int|string}  $pr
     */
    private function stampSuccess(AgentRun $run, array $pr): void
    {
        $output = (array) $run->output;
        $output['pull_request_url'] = $pr['url'];
        $output['pull_request_number'] = $pr['number'];
        unset($output['pull_request_error']);
        $run->forceFill(['output' => $output])->save();

        Log::info('specify.subtask.pr_retry.opened', [
            'run_id' => $run->getKey(),
            'url' => $pr['url'],
        ]);
    }

    private function recordError(AgentRun $run, string $message): void
    {
        $output = (array) $run->output;
        $output['pull_request_error'] = $message;
        $run->forceFill(['output' => $output])->save();

        Log::warning('specify.subtask.pr_retry.failed', [
            'run_id' => $run->getKey(),
            'error' => $message,
        ]);
    }
}
