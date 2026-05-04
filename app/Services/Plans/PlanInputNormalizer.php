<?php

namespace App\Services\Plans;

use App\Models\Story;

class PlanInputNormalizer
{
    /**
     * @param  array<int, array<string, mixed>>  $rawTasks
     * @return list<array<string, mixed>>
     */
    public function fromGeneratedTasks(Story $story, array $rawTasks): array
    {
        $criteria = $story->relationLoaded('acceptanceCriteria')
            ? $story->acceptanceCriteria
            : $story->acceptanceCriteria()->get();
        $criteriaByPosition = $criteria->keyBy('position');

        $tasks = [];
        foreach ($rawTasks as $taskData) {
            $acPosition = $taskData['acceptance_criterion_position'] ?? null;
            $criterion = $acPosition !== null ? ($criteriaByPosition[$acPosition] ?? null) : null;

            $tasks[] = [
                'position' => $taskData['position'],
                'name' => $taskData['name'],
                'description' => $taskData['description'] ?? null,
                'acceptance_criterion_id' => $criterion?->getKey(),
                'depends_on_positions' => $this->positionList($taskData['depends_on'] ?? []),
                'subtasks' => array_map(
                    fn (array $subtaskData) => [
                        'position' => $subtaskData['position'],
                        'name' => $subtaskData['name'],
                        'description' => $subtaskData['description'] ?? null,
                    ],
                    $taskData['subtasks'] ?? [],
                ),
            ];
        }

        return $tasks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    public function forPlanWriter(array $tasks): array
    {
        return array_values(array_map(
            fn (array $taskData) => [
                'position' => $taskData['position'],
                'name' => $taskData['name'],
                'description' => $taskData['description'] ?? null,
                'acceptance_criterion_id' => $taskData['acceptance_criterion_id'] ?? null,
                'scenario_id' => $taskData['scenario_id'] ?? null,
                'depends_on_positions' => $this->positionList($taskData['depends_on_positions'] ?? []),
                'subtasks' => array_values(array_map(
                    fn (array $subtaskData) => [
                        'position' => $subtaskData['position'],
                        'name' => $subtaskData['name'],
                        'description' => $subtaskData['description'] ?? null,
                    ],
                    $taskData['subtasks'] ?? [],
                )),
            ],
            $tasks,
        ));
    }

    /**
     * @return list<int>
     */
    private function positionList(mixed $positions): array
    {
        if (! is_array($positions)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($position) => (int) $position, $positions),
            static fn (int $position) => $position >= 1,
        )));
    }
}
