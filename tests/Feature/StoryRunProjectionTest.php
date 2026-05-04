<?php

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Stories\StoryRunProjection;

test('story run projection detects active subtask runs under the current plan', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->forCurrentPlanOf($story)->create();
    $subtask = Subtask::factory()->for($task)->create();

    $oldPlan = Plan::factory()->for($story)->create(['version' => 1]);
    $oldTask = Task::factory()->for($oldPlan)->create();
    $oldSubtask = Subtask::factory()->for($oldTask)->create();

    expect(app(StoryRunProjection::class)->hasActiveSubtaskRun($story))->toBeFalse();

    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $oldSubtask->id,
        'status' => AgentRunStatus::Running,
    ]);

    expect(app(StoryRunProjection::class)->hasActiveSubtaskRun($story->fresh()))->toBeFalse();

    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Running,
    ]);

    expect(app(StoryRunProjection::class)->hasActiveSubtaskRun($story->fresh()))->toBeTrue();
});

test('story run projection returns latest active conflict resolution run', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->forCurrentPlanOf($story)->create();
    $subtask = Subtask::factory()->for($task)->create();

    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'kind' => AgentRunKind::ResolveConflicts,
        'status' => AgentRunStatus::Failed,
    ]);
    $running = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'kind' => AgentRunKind::ResolveConflicts,
        'status' => AgentRunStatus::Running,
    ]);

    $result = app(StoryRunProjection::class)->activeConflictResolutionRun($story->fresh());

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($running->id);
});
