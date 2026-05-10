<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Plans\PlanRunProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('latestRun returns null for a plan with no runs', function () {
    $story = makeStory();
    $plan = Plan::factory()->for($story)->create();
    Task::factory()->for($plan)->create(['position' => 1]);

    $plan->load('tasks.subtasks.agentRuns');

    expect(app(PlanRunProjection::class)->latestRun($plan))->toBeNull();
});

test('latestRun returns the highest-id AgentRun across all subtasks of the plan', function () {
    $story = makeStory();
    $plan = Plan::factory()->for($story)->create();
    $taskA = Task::factory()->for($plan)->create(['position' => 1]);
    $taskB = Task::factory()->for($plan)->create(['position' => 2]);
    $subA = Subtask::factory()->for($taskA)->create(['position' => 1]);
    $subB = Subtask::factory()->for($taskB)->create(['position' => 1]);

    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subA->id,
        'status' => AgentRunStatus::Succeeded,
        'working_branch' => 'first-branch',
    ]);
    $latest = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subB->id,
        'status' => AgentRunStatus::Succeeded,
        'working_branch' => 'second-branch',
    ]);

    $plan->load('tasks.subtasks.agentRuns.repo');

    expect(app(PlanRunProjection::class)->latestRun($plan)?->id)->toBe($latest->id);
});
