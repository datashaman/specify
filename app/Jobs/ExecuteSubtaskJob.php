<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Subtask;
use App\Services\ExecutionService;
use App\Services\Executors\ExecutorFactory;
use App\Services\SubtaskRunOutcome;
use App\Services\SubtaskRunPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue job that runs a single Subtask AgentRun via `SubtaskRunPipeline`.
 *
 * Owns only queue-lifecycle concerns: load the AgentRun, mark Running, resolve
 * the executor named on the run via `ExecutorFactory`, hand off to the
 * pipeline, then translate the outcome into a markSucceeded / markFailed
 * call. Exceptions from the pipeline mark Failed and rethrow so the queue
 * can decide whether to retry.
 *
 * The executor is resolved per-run from `AgentRun.executor_driver` so race-
 * mode siblings each run on the driver they were dispatched with.
 */
class ExecuteSubtaskJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    /** Queue handler — see class docblock. */
    public function handle(ExecutionService $execution, SubtaskRunPipeline $pipeline, ExecutorFactory $executors): void
    {
        $run = AgentRun::findOrFail($this->agentRunId);

        if (! $run->runnable instanceof Subtask) {
            $execution->markFailed($run, 'Subtask for AgentRun not found.');

            return;
        }

        $execution->markRunning($run);

        $driver = $run->executor_driver ?? $executors->defaultDriver();

        try {
            $executor = $executors->make($driver);
            $outcome = $pipeline->run($run, $executor);
        } catch (Throwable $e) {
            Log::error('specify.subtask.run.failed', [
                'run_id' => $run->getKey(),
                'subtask_id' => $run->runnable->getKey(),
                'executor_driver' => $driver,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $execution->markFailed($run, $e->getMessage());

            throw $e;
        }

        match ($outcome->state) {
            SubtaskRunOutcome::STATE_SUCCEEDED => $execution->markSucceeded($run, $outcome->output, $outcome->diff),
            SubtaskRunOutcome::STATE_NO_DIFF,
            SubtaskRunOutcome::STATE_PULL_REQUEST_FAILED => $execution->markFailed($run, $outcome->error ?? 'Subtask run failed.'),
        };

        if ($outcome->state === SubtaskRunOutcome::STATE_SUCCEEDED) {
            $this->dispatchAdvisoryReview($run->fresh());
        }
    }

    /**
     * Fire the ADR-conformance review job after the AgentRun has been
     * persisted by markSucceeded. Dispatched here (not from the pipeline)
     * so the queued job sees `output.pull_request_number` and `diff`
     * already on the row — even on non-sync queues.
     */
    private function dispatchAdvisoryReview(?AgentRun $run): void
    {
        if ($run === null) {
            return;
        }
        if (! (bool) config('specify.review.enabled', false)) {
            return;
        }
        $personas = (array) config('specify.review.personas', []);
        if (! in_array('adr-conformance', $personas, true)) {
            return;
        }
        $output = (array) $run->output;
        if (! isset($output['pull_request_number'])) {
            return;
        }

        ReviewPullRequestJob::dispatch($run->getKey());
        Log::info('specify.review.dispatched', [
            'run_id' => $run->getKey(),
            'pr_number' => $output['pull_request_number'],
        ]);
    }
}
