<?php

namespace App\Jobs;

use App\Ai\Agents\TasksGenerator;
use App\Models\AgentRun;
use App\Models\Story;
use App\Services\ExecutionService;
use App\Services\PlanWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateTasksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(ExecutionService $execution, PlanWriter $planWriter): void
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

            $tasks = $this->normalizeTasks($story, $output['tasks'] ?? []);
            $result = $planWriter->replacePlan($story, $tasks);

            $execution->markSucceeded($run, [
                'summary' => $output['summary'] ?? null,
                'task_count' => $result['task_count'],
                'subtask_count' => $result['subtask_count'],
            ]);
        } catch (Throwable $e) {
            $execution->markFailed($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Map agent-generated tasks (referencing acceptance criteria by position
     * and using `depends_on`) into the plan-writer shape (referencing by id
     * and using `depends_on_positions`).
     *
     * @param  array<int, array<string, mixed>>  $rawTasks
     * @return list<array<string, mixed>>
     */
    private function normalizeTasks(Story $story, array $rawTasks): array
    {
        $criteriaByPosition = $story->acceptanceCriteria()->get()->keyBy('position');

        $tasks = [];
        foreach ($rawTasks as $taskData) {
            $acPosition = $taskData['acceptance_criterion_position'] ?? null;
            $criterion = $acPosition !== null ? ($criteriaByPosition[$acPosition] ?? null) : null;

            $tasks[] = [
                'position' => $taskData['position'],
                'name' => $taskData['name'],
                'description' => $taskData['description'] ?? null,
                'acceptance_criterion_id' => $criterion?->getKey(),
                'depends_on_positions' => $taskData['depends_on'] ?? [],
                'subtasks' => array_map(
                    fn (array $s) => [
                        'position' => $s['position'],
                        'name' => $s['name'],
                        'description' => $s['description'] ?? null,
                    ],
                    $taskData['subtasks'] ?? [],
                ),
            ];
        }

        return $tasks;
    }
}
