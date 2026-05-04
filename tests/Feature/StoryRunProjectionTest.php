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

    $oldPlan = Plan::factory()->for($story)->create(['version' => 2]);
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

test('story run projection prepares current plan activity view data', function () {
    $story = Story::factory()->create();
    $ac = $story->acceptanceCriteria()->create([
        'position' => 1,
        'statement' => 'The current plan maps work to this AC.',
    ]);
    $task = Task::factory()->forCurrentPlanOf($story)->create([
        'acceptance_criterion_id' => $ac->getKey(),
    ]);
    $subtask = Subtask::factory()->for($task)->create();
    $older = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
        'working_branch' => 'specify/older',
    ]);
    $latest = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Running,
        'working_branch' => 'specify/latest',
    ]);
    $planRun = AgentRun::factory()->create([
        'runnable_type' => Story::class,
        'runnable_id' => $story->id,
        'status' => AgentRunStatus::Succeeded,
    ]);

    $data = app(StoryRunProjection::class)->currentPlanViewData($story->fresh()->load([
        'acceptanceCriteria',
        'currentPlanTasks.subtasks.agentRuns.repo',
    ]));

    expect($data['tasksByAc']->get($ac->getKey())->first()->id)->toBe($task->id)
        ->and($data['branch'])->toBe('specify/latest')
        ->and($data['planGenRuns']->pluck('id')->all())->toBe([$planRun->id])
        ->and($older->id)->toBeLessThan($latest->id);
});
