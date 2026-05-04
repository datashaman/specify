<?php

use App\Enums\TaskStatus;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a subtask is only actionable when all lower-position siblings in the same task are Done', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->forStory($story)->create(['position' => 0]);
    $a = Subtask::factory()->for($task)->create(['position' => 0, 'status' => TaskStatus::Pending]);
    $b = Subtask::factory()->for($task)->create(['position' => 1, 'status' => TaskStatus::Pending]);
    $c = Subtask::factory()->for($task)->create(['position' => 2, 'status' => TaskStatus::Pending]);

    $service = app(ExecutionService::class);

    $next = $service->nextActionableSubtasks($story->fresh());
    expect($next->pluck('id')->all())->toBe([$a->id]);

    $a->forceFill(['status' => TaskStatus::Done->value])->save();
    $next = $service->nextActionableSubtasks($story->fresh());
    expect($next->pluck('id')->all())->toBe([$b->id]);

    $b->forceFill(['status' => TaskStatus::Done->value])->save();
    $next = $service->nextActionableSubtasks($story->fresh());
    expect($next->pluck('id')->all())->toBe([$c->id]);
});

test('subtasks of a task with unfinished dependencies are not actionable', function () {
    $story = Story::factory()->create();
    $blocker = Task::factory()->forStory($story)->create(['position' => 0, 'status' => TaskStatus::Pending]);
    Subtask::factory()->for($blocker)->create(['position' => 0]);

    $dependent = Task::factory()->forStory($story)->create(['position' => 1, 'status' => TaskStatus::Pending]);
    Subtask::factory()->for($dependent)->create(['position' => 0]);
    $dependent->addDependency($blocker);

    $next = app(ExecutionService::class)->nextActionableSubtasks($story->fresh());

    expect($next->pluck('task_id')->unique()->values()->all())->toBe([$blocker->id]);
});
