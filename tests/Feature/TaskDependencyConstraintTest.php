<?php

use App\Models\Story;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('task dependencies must live in the same plan', function () {
    $storyA = Story::factory()->create();
    $storyB = Story::factory()->create();

    $a = Task::factory()->forCurrentPlanOf($storyA)->create();
    $b = Task::factory()->forCurrentPlanOf($storyB)->create();

    expect(fn () => $b->addDependency($a))
        ->toThrow(InvalidArgumentException::class, 'same plan');
});

test('task dependencies within the same story are allowed', function () {
    $story = Story::factory()->create();
    $a = Task::factory()->forCurrentPlanOf($story)->create();
    $b = Task::factory()->forCurrentPlanOf($story)->create();

    $b->addDependency($a);

    expect($b->dependencies->pluck('id')->all())->toBe([$a->id]);
});
