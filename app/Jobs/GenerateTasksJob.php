<?php

namespace App\Jobs;

use App\Ai\Agents\TasksGenerator;
use App\Enums\PlanSource;
use App\Models\AgentRun;
use App\Models\Story;
use App\Services\ExecutionService;
use App\Services\Plans\PlanInputNormalizer;
use App\Services\PlanWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue job that asks the `TasksGenerator` agent to produce a Story's implementation plan.
 *
 * The agent returns structured tasks + subtasks; this job normalises them into
 * the `PlanWriter::replacePlan()` shape and persists a new current plan in one
 * transaction. The write reopens Plan approval so reviewers approve the new plan.
 */
class GenerateTasksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    /** Queue handler — see class docblock. */
    public function handle(ExecutionService $execution, PlanInputNormalizer $planInputs, PlanWriter $planWriter): void
    {
        $run = AgentRun::findOrFail($this->agentRunId);
        $story = $run->runnable;

        if (! $story instanceof Story) {
            $execution->markFailed($run, 'Story for AgentRun not found.');

            return;
        }

        $execution->markRunning($run);

        try {
            $agent = new TasksGenerator($story);
            $response = $agent->prompt($agent->buildPrompt());
            $output = $response->toArray();

            $tasks = $planInputs->fromGeneratedTasks($story, $output['tasks'] ?? []);
            $result = $planWriter->replacePlan($story, $tasks, [
                'name' => 'AI plan v'.(((int) $story->plans()->max('version')) + 1),
                'summary' => $output['summary'] ?? null,
                'source' => PlanSource::Ai,
                'source_label' => 'TasksGenerator',
            ]);

            $execution->markSucceeded($run, [
                'summary' => $output['summary'] ?? null,
                'plan_id' => $result['plan_id'],
                'task_count' => $result['task_count'],
                'subtask_count' => $result['subtask_count'],
            ]);
        } catch (Throwable $e) {
            $execution->markFailed($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Queue failure callback — covers worker death / retry exhaustion that
     * the catch block in `handle()` can't see. `markFailed` is idempotent so
     * a double-mark with the catch block is harmless.
     */
    public function failed(?Throwable $e): void
    {
        $run = AgentRun::find($this->agentRunId);
        if ($run === null || $run->isTerminal()) {
            return;
        }

        Log::error('specify.tasks.generate.job_failed', [
            'run_id' => $run->getKey(),
            'story_id' => $run->runnable_id,
            'exception' => $e?->getMessage(),
        ]);

        app(ExecutionService::class)->markFailed(
            $run,
            $e?->getMessage() ?: 'Job failed without surfacing an exception (worker died or retries exhausted).',
        );
    }
}
