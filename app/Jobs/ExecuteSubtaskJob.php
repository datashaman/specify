<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Subtask;
use App\Services\ExecutionService;
use App\Services\SubtaskRunOutcome;
use App\Services\SubtaskRunPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteSubtaskJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(ExecutionService $execution, SubtaskRunPipeline $pipeline): void
    {
        $run = AgentRun::findOrFail($this->agentRunId);

        if (! $run->runnable instanceof Subtask) {
            $execution->markFailed($run, 'Subtask for AgentRun not found.');

            return;
        }

        $execution->markRunning($run);

        try {
            $outcome = $pipeline->run($run);
        } catch (Throwable $e) {
            Log::error('specify.subtask.run.failed', [
                'run_id' => $run->getKey(),
                'subtask_id' => $run->runnable->getKey(),
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
    }
}
