<?php

namespace App\Jobs;

use App\Ai\Agents\PlanGenerator;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Models\Task;
use App\Services\ExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentRunId) {}

    public function handle(ExecutionService $execution): void
    {
        $run = AgentRun::findOrFail($this->agentRunId);
        $story = $run->runnable;

        if ($story === null) {
            $execution->markFailed($run, 'Story for AgentRun not found.');

            return;
        }

        $execution->markRunning($run);

        try {
            $agent = new PlanGenerator($story);
            $response = $agent->prompt($agent->buildPrompt());
            $output = $response->toArray();

            $plan = DB::transaction(function () use ($story, $output) {
                $version = ($story->plans()->max('version') ?? 0) + 1;

                $plan = Plan::create([
                    'story_id' => $story->getKey(),
                    'version' => $version,
                    'summary' => $output['summary'] ?? null,
                ]);

                $byPosition = [];
                foreach ($output['tasks'] ?? [] as $task) {
                    $created = Task::create([
                        'plan_id' => $plan->getKey(),
                        'position' => $task['position'],
                        'name' => $task['name'],
                        'description' => $task['description'] ?? null,
                    ]);
                    $byPosition[$task['position']] = $created;
                }

                foreach ($output['tasks'] ?? [] as $task) {
                    foreach ($task['depends_on'] ?? [] as $dependsOnPosition) {
                        if (! isset($byPosition[$task['position']], $byPosition[$dependsOnPosition])) {
                            continue;
                        }
                        $byPosition[$task['position']]->addDependency($byPosition[$dependsOnPosition]);
                    }
                }

                $story->forceFill(['current_plan_id' => $plan->getKey()])->save();

                return $plan;
            });

            $execution->markSucceeded($run, [
                'plan_id' => $plan->getKey(),
                'plan_version' => $plan->version,
                'summary' => $plan->summary,
                'task_count' => $plan->tasks()->count(),
            ]);
        } catch (Throwable $e) {
            $execution->markFailed($run, $e->getMessage());

            throw $e;
        }
    }
}
