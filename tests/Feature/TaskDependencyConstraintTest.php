<?php

use App\Models\Story;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('task dependencies must live in the same story', function () {
    $storyA = Story::factory()->create();
    $storyB = Story::factory()->create();

    $a = Task::factory()->for($storyA)->create();
    $b = Task::factory()->for($storyB)->create();

    expect(fn () => $b->addDependency($a))
        ->toThrow(InvalidArgumentException::class, 'same story');
});

test('task dependencies within the same story are allowed', function () {
    $story = Story::factory()->create();
    $a = Task::factory()->for($story)->create();
    $b = Task::factory()->for($story)->create();

    $b->addDependency($a);

    expect($b->dependencies->pluck('id')->all())->toBe([$a->id]);
});
