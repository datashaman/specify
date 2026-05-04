<?php

namespace App\Services;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Executors\ProposedSubtask;
use App\Services\Plans\PlanInputNormalizer;
use App\Services\Plans\PlanVersionAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Replaces the current implementation plan of a Story atomically.
 *
 * Each replacement creates a fresh Plan version, writes Tasks/Subtasks under
 * that plan, marks the prior current plan superseded, and points the Story at
 * the new current plan.
 */
class PlanWriter
{
    public function __construct(
        private PlanInputNormalizer $planInputs,
        private PlanVersionAllocator $planVersions,
    ) {}

    /**
     * @param  list<array{
     *     position: int,
     *     name: string,
     *     description?: ?string,
     *     acceptance_criterion_id?: ?int,
     *     scenario_id?: ?int,
     *     depends_on_positions?: list<int>,
     *     subtasks: list<array{position: int, name: string, description?: ?string}>,
     * }>  $tasks
     * @param  array{name?: ?string, summary?: ?string, source?: ?PlanSource, source_label?: ?string, status?: ?PlanStatus}  $attributes
     * @return array{plan_id: int, task_count: int, subtask_count: int}
     */
    public function replacePlan(Story $story, array $tasks, array $attributes = []): array
    {
        $tasks = $this->planInputs->forPlanWriter($tasks);

        $this->validate($story, $tasks);

        $result = $this->planVersions->withNextVersion($story, function (int $nextVersion, Story $story) use ($tasks, $attributes) {
            $previousPlan = $story->currentPlan;

            if ($previousPlan) {
                $previousPlan->forceFill(['status' => PlanStatus::Superseded])->save();
            }

            $plan = Plan::create([
                'story_id' => $story->getKey(),
                'version' => $nextVersion,
                'revision' => 1,
                'name' => $attributes['name'] ?? $this->defaultPlanName($attributes['source'] ?? PlanSource::Human, $nextVersion),
                'summary' => $attributes['summary'] ?? null,
                'source' => $attributes['source'] ?? PlanSource::Human,
                'source_label' => $attributes['source_label'] ?? null,
                'status' => $attributes['status'] ?? ($story->status === StoryStatus::Approved
                    ? PlanStatus::PendingApproval
                    : PlanStatus::Draft),
            ]);

            $tasksByPosition = [];

            foreach ($tasks as $taskData) {
                $task = Task::create([
                    'plan_id' => $plan->getKey(),
                    'acceptance_criterion_id' => $taskData['acceptance_criterion_id'] ?? null,
                    'scenario_id' => $taskData['scenario_id'] ?? null,
                    'position' => $taskData['position'],
                    'name' => $taskData['name'],
                    'description' => $taskData['description'] ?? null,
                ]);

                foreach ($taskData['subtasks'] as $subtaskData) {
                    Subtask::create([
                        'task_id' => $task->getKey(),
                        'position' => $subtaskData['position'],
                        'name' => $subtaskData['name'],
                        'description' => $subtaskData['description'] ?? null,
                    ]);
                }

                $tasksByPosition[$taskData['position']] = $task;
            }

            foreach ($tasks as $taskData) {
                foreach ($taskData['depends_on_positions'] ?? [] as $depPosition) {
                    if (! isset($tasksByPosition[$taskData['position']], $tasksByPosition[$depPosition])) {
                        continue;
                    }
                    $tasksByPosition[$taskData['position']]->addDependency($tasksByPosition[$depPosition]);
                }
            }

            $story->silentlyForceFill([
                'current_plan_id' => $plan->getKey(),
            ]);

            return [
                'plan_id' => $plan->getKey(),
                'task_count' => count($tasksByPosition),
                'subtask_count' => Subtask::whereIn('task_id', collect($tasksByPosition)->map->getKey()->all())->count(),
            ];
        });

        return $result;
    }

    private function defaultPlanName(PlanSource $source, int $version): string
    {
        return match ($source) {
            PlanSource::Ai => 'AI plan v'.$version,
            default => 'Plan v'.$version,
        };
    }

    /**
     * Append-only growth of a Task's Subtask list driven by a successful
     * executor run (ADR-0005). Unlike `replacePlan()`, this does NOT reset
     * Story approval, because intent and existing Subtasks are unchanged.
     *
     * Caps at three appended Subtasks per call; surplus is discarded with a
     * warning so a runaway agent cannot extend the plan unboundedly.
     *
     * @param  list<ProposedSubtask>  $proposed
     * @return list<Subtask> The Subtasks that were created (in execution order).
     */
    public function appendProposedSubtasks(Task $task, array $proposed, AgentRun $run): array
    {
        if ($proposed === []) {
            return [];
        }

        $cap = 3;
        if (count($proposed) > $cap) {
            Log::warning('specify.plan.proposed_subtasks.capped', [
                'task_id' => $task->getKey(),
                'run_id' => $run->getKey(),
                'received' => count($proposed),
                'cap' => $cap,
            ]);
            $proposed = array_slice($proposed, 0, $cap);
        }

        return DB::transaction(function () use ($task, $proposed, $run) {
            $startPosition = ((int) $task->subtasks()->max('position')) + 1;
            $created = [];

            foreach ($proposed as $i => $entry) {
                $created[] = Subtask::create([
                    'task_id' => $task->getKey(),
                    'position' => $startPosition + $i,
                    'name' => $entry->name,
                    'description' => $entry->description."\n\n_Reason:_ ".$entry->reason,
                    'proposed_by_run_id' => $run->getKey(),
                ]);
            }

            return $created;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function validate(Story $story, array $tasks): void
    {
        if ($tasks === []) {
            throw new InvalidArgumentException('Plan must contain at least one task.');
        }

        $taskPositions = array_column($tasks, 'position');
        if (count($taskPositions) !== count(array_unique($taskPositions))) {
            throw new InvalidArgumentException('Task positions must be unique within a story.');
        }

        $allowedAcIds = $story->acceptanceCriteria()->pluck('id')->all();
        $allowedScenarioIds = $story->scenarios()->pluck('id')->all();

        foreach ($tasks as $taskData) {
            if (! empty($taskData['acceptance_criterion_id'])) {
                if (! in_array($taskData['acceptance_criterion_id'], $allowedAcIds, true)) {
                    throw new InvalidArgumentException(
                        "acceptance_criterion_id {$taskData['acceptance_criterion_id']} does not belong to this story."
                    );
                }
            }

            if (! empty($taskData['scenario_id']) && ! in_array($taskData['scenario_id'], $allowedScenarioIds, true)) {
                throw new InvalidArgumentException(
                    "scenario_id {$taskData['scenario_id']} does not belong to this story."
                );
            }

            if (empty($taskData['subtasks'])) {
                throw new InvalidArgumentException(
                    "Task at position {$taskData['position']} must have at least one subtask."
                );
            }

            $subPositions = array_column($taskData['subtasks'], 'position');
            if (count($subPositions) !== count(array_unique($subPositions))) {
                throw new InvalidArgumentException(
                    "Subtask positions must be unique within task at position {$taskData['position']}."
                );
            }
        }
    }
}
