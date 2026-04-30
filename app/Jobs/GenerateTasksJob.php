<?php

namespace App\Jobs;

use App\Ai\Agents\TasksGenerator;
use App\Enums\StoryStatus;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateTasksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(ExecutionService $execution): void
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

            $result = DB::transaction(function () use ($story, $output) {
                $story->tasks()->delete();

                $criteriaByPosition = $story->acceptanceCriteria()
                    ->get()
                    ->keyBy('position');

                $tasksByPosition = [];

                foreach ($output['tasks'] ?? [] as $taskData) {
                    $acPosition = $taskData['acceptance_criterion_position'] ?? null;
                    $criterion = $acPosition !== null ? ($criteriaByPosition[$acPosition] ?? null) : null;

                    $task = Task::create([
                        'story_id' => $story->getKey(),
                        'acceptance_criterion_id' => $criterion?->getKey(),
                        'position' => $taskData['position'],
                        'name' => $taskData['name'],
                        'description' => $taskData['description'] ?? null,
                    ]);

                    foreach ($taskData['subtasks'] ?? [] as $subtaskData) {
                        Subtask::create([
                            'task_id' => $task->getKey(),
                            'position' => $subtaskData['position'],
                            'name' => $subtaskData['name'],
                            'description' => $subtaskData['description'] ?? null,
                        ]);
                    }

                    $tasksByPosition[$taskData['position']] = $task;
                }

                foreach ($output['tasks'] ?? [] as $taskData) {
                    foreach ($taskData['depends_on'] ?? [] as $dependsOnPosition) {
                        if (! isset($tasksByPosition[$taskData['position']], $tasksByPosition[$dependsOnPosition])) {
                            continue;
                        }
                        $tasksByPosition[$taskData['position']]->addDependency($tasksByPosition[$dependsOnPosition]);
                    }
                }

                if ($story->status === StoryStatus::Approved) {
                    $story->forceFill([
                        'status' => StoryStatus::PendingApproval->value,
                        'revision' => ($story->revision ?? 1) + 1,
                    ])->save();
                } elseif ($story->status === StoryStatus::ChangesRequested) {
                    $story->forceFill(['status' => StoryStatus::PendingApproval->value])->save();
                }

                return [
                    'task_count' => count($tasksByPosition),
                    'subtask_count' => Subtask::whereIn('task_id', collect($tasksByPosition)->map->getKey()->all())->count(),
                ];
            });

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
}
