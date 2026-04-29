<?php

use App\Ai\Agents\PlanGenerator;
use App\Enums\AgentRunStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
});

test('dispatching plan generation runs the job, creates Plan with Tasks and dependencies, marks AgentRun succeeded', function () {
    $story = makeStory();
    AcceptanceCriterion::factory()->for($story)->create(['criterion' => 'It works']);

    PlanGenerator::fake(fn () => [
        'summary' => 'Three-step plan.',
        'tasks' => [
            ['name' => 'a', 'description' => 'first', 'position' => 0, 'depends_on' => []],
            ['name' => 'b', 'description' => 'second', 'position' => 1, 'depends_on' => [0]],
            ['name' => 'c', 'description' => 'third', 'position' => 2, 'depends_on' => [0]],
        ],
    ]);

    $run = app(ExecutionService::class)->dispatchPlanGeneration($story);

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Succeeded);

    $plan = Plan::where('story_id', $story->id)->firstOrFail();
    expect($plan->summary)->toBe('Three-step plan.')
        ->and($plan->tasks)->toHaveCount(3)
        ->and($story->fresh()->current_plan_id)->toBe($plan->id);

    $b = $plan->tasks->firstWhere('name', 'b');
    $c = $plan->tasks->firstWhere('name', 'c');
    expect($b->dependencies->pluck('name')->all())->toBe(['a'])
        ->and($c->dependencies->pluck('name')->all())->toBe(['a']);

    expect($run->output)->toMatchArray([
        'plan_id' => $plan->id,
        'plan_version' => 1,
        'task_count' => 3,
    ]);
});

test('regeneration increments version and updates current_plan_id', function () {
    $story = makeStory();

    PlanGenerator::fake(fn () => [
        'summary' => 'v1',
        'tasks' => [['name' => 'one', 'description' => 'do one', 'position' => 0]],
    ]);

    app(ExecutionService::class)->dispatchPlanGeneration($story);
    $v1 = Plan::where('story_id', $story->id)->latest('id')->first();

    PlanGenerator::fake(fn () => [
        'summary' => 'v2',
        'tasks' => [
            ['name' => 'one', 'description' => 'rev', 'position' => 0],
            ['name' => 'two', 'description' => 'new', 'position' => 1, 'depends_on' => [0]],
        ],
    ]);

    app(ExecutionService::class)->dispatchPlanGeneration($story);
    $v2 = Plan::where('story_id', $story->id)->latest('id')->first();

    expect($v1->version)->toBe(1)
        ->and($v2->version)->toBe(2)
        ->and($story->fresh()->current_plan_id)->toBe($v2->id)
        ->and($v2->tasks)->toHaveCount(2);
});

test('agent failure marks the AgentRun failed with error message', function () {
    $story = makeStory();

    PlanGenerator::fake(function () {
        throw new RuntimeException('upstream timed out');
    });

    try {
        app(ExecutionService::class)->dispatchPlanGeneration($story);
    } catch (Throwable $e) {
        // sync queue rethrows
    }

    $run = AgentRun::where('runnable_id', $story->id)->latest('id')->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error_message)->toContain('upstream timed out')
        ->and(Plan::where('story_id', $story->id)->count())->toBe(0);
});

test('agent receives a prompt that mentions story name and acceptance criteria', function () {
    $story = makeStory();
    $story->forceFill(['name' => 'Add export', 'description' => 'export users as CSV'])->save();
    AcceptanceCriterion::factory()->for($story)->create(['criterion' => 'CSV downloads']);

    PlanGenerator::fake(fn () => [
        'summary' => 'noop',
        'tasks' => [['name' => 't', 'description' => 'd', 'position' => 0]],
    ]);

    app(ExecutionService::class)->dispatchPlanGeneration($story);

    PlanGenerator::assertPrompted(function ($prompt) {
        return str_contains($prompt->prompt, 'Add export')
            && str_contains($prompt->prompt, 'CSV downloads');
    });
});
