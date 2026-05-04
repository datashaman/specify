<?php

use App\Models\AcceptanceCriterion;
use App\Services\Plans\PlanInputNormalizer;

test('generated tasks are normalized into plan writer input', function () {
    $story = makeStory();
    $criterion = AcceptanceCriterion::factory()->for($story)->create(['position' => 2]);

    $tasks = app(PlanInputNormalizer::class)->fromGeneratedTasks($story, [
        [
            'position' => 1,
            'name' => 'Build shared support',
            'subtasks' => [
                ['position' => 1, 'name' => 'Wire support'],
            ],
        ],
        [
            'position' => 2,
            'name' => 'Criterion work',
            'acceptance_criterion_position' => 2,
            'depends_on' => [1],
            'subtasks' => [
                ['position' => 1, 'name' => 'Implement criterion'],
            ],
        ],
        [
            'position' => 3,
            'name' => 'Cross-cutting verification',
            'depends_on' => [2, 2, 0, -1, 'bad'],
            'subtasks' => [
                ['position' => 1, 'name' => 'Verify all paths', 'description' => 'Run checks.'],
            ],
        ],
    ]);

    expect($tasks)->toBe([
        [
            'position' => 1,
            'name' => 'Build shared support',
            'description' => null,
            'acceptance_criterion_id' => null,
            'depends_on_positions' => [],
            'subtasks' => [
                ['position' => 1, 'name' => 'Wire support', 'description' => null],
            ],
        ],
        [
            'position' => 2,
            'name' => 'Criterion work',
            'description' => null,
            'acceptance_criterion_id' => $criterion->id,
            'depends_on_positions' => [1],
            'subtasks' => [
                ['position' => 1, 'name' => 'Implement criterion', 'description' => null],
            ],
        ],
        [
            'position' => 3,
            'name' => 'Cross-cutting verification',
            'description' => null,
            'acceptance_criterion_id' => null,
            'depends_on_positions' => [2],
            'subtasks' => [
                ['position' => 1, 'name' => 'Verify all paths', 'description' => 'Run checks.'],
            ],
        ],
    ]);
});

test('plan writer input is canonicalized before validation and writing', function () {
    $tasks = app(PlanInputNormalizer::class)->forPlanWriter([
        7 => [
            'position' => 3,
            'name' => 'Task',
            'depends_on_positions' => [1, 1, 0, 'bad'],
            'subtasks' => [
                9 => ['position' => 1, 'name' => 'Subtask'],
            ],
        ],
    ]);

    expect($tasks)->toBe([
        [
            'position' => 3,
            'name' => 'Task',
            'description' => null,
            'acceptance_criterion_id' => null,
            'scenario_id' => null,
            'depends_on_positions' => [1],
            'subtasks' => [
                ['position' => 1, 'name' => 'Subtask', 'description' => null],
            ],
        ],
    ]);
});
