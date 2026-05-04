<?php

use App\Ai\Agents\TasksGenerator;
use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\User;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
});

test('dispatching task generation runs the job, creates Tasks linked to ACs with Subtasks, marks AgentRun succeeded', function () {
    $story = Story::factory()->create();
    $ac1 = AcceptanceCriterion::factory()->for($story)->create(['position' => 1, 'statement' => 'AC one']);
    $ac2 = AcceptanceCriterion::factory()->for($story)->create(['position' => 2, 'statement' => 'AC two']);

    TasksGenerator::fake(fn () => [
        'summary' => 'plan it',
        'tasks' => [
            [
                'name' => 't0', 'description' => 'desc0', 'position' => 1,
                'acceptance_criterion_position' => 1,
                'subtasks' => [
                    ['name' => 's0', 'description' => 'sd0', 'position' => 1],
                    ['name' => 's1', 'description' => 'sd1', 'position' => 2],
                ],
            ],
            [
                'name' => 't1', 'description' => 'desc1', 'position' => 2,
                'acceptance_criterion_position' => 2,
                'depends_on' => [1],
                'subtasks' => [
                    ['name' => 's0', 'description' => 'sd0', 'position' => 1],
                ],
            ],
        ],
    ]);

    $run = app(ExecutionService::class)->dispatchTaskGeneration($story);

    $run->refresh();
    expect($run->status)->toBe(AgentRunStatus::Succeeded);

    $tasks = $story->fresh()->currentPlanTasks()->orderBy('position')->get();
    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->acceptance_criterion_id)->toBe($ac1->id)
        ->and($tasks[1]->acceptance_criterion_id)->toBe($ac2->id)
        ->and($tasks[0]->subtasks()->count())->toBe(2)
        ->and($tasks[1]->subtasks()->count())->toBe(1)
        ->and($tasks[1]->dependencies->pluck('id')->all())->toBe([$tasks[0]->id]);
});

test('regeneration replaces the prior task list', function () {
    $story = Story::factory()->create();
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1, 'statement' => 'AC one']);

    TasksGenerator::fake(fn () => [
        'summary' => 'v1',
        'tasks' => [[
            'name' => 'task-v1', 'description' => 'd', 'position' => 1,
            'acceptance_criterion_position' => 1,
            'subtasks' => [['name' => 's', 'description' => 'sd', 'position' => 1]],
        ]],
    ]);

    app(ExecutionService::class)->dispatchTaskGeneration($story);
    expect($story->fresh()->currentPlanTasks()->count())->toBe(1);

    TasksGenerator::fake(fn () => [
        'summary' => 'v2',
        'tasks' => [[
            'name' => 'task-v2', 'description' => 'd', 'position' => 1,
            'acceptance_criterion_position' => 1,
            'subtasks' => [['name' => 's', 'description' => 'sd', 'position' => 1]],
        ]],
    ]);

    app(ExecutionService::class)->dispatchTaskGeneration($story);
    $tasks = $story->fresh()->currentPlanTasks;
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->name)->toBe('task-v2')
        ->and($story->fresh()->currentPlan->version)->toBe(2)
        ->and($story->fresh()->currentPlan->name)->toBe('AI plan v2');
});

test('task generation allows cross-cutting tasks without a single acceptance criterion', function () {
    $story = Story::factory()->create();
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1, 'statement' => 'AC one']);

    TasksGenerator::fake(fn () => [
        'summary' => 'plan it',
        'tasks' => [[
            'name' => 'shared setup',
            'description' => 'Prepare shared infrastructure for multiple criteria.',
            'position' => 1,
            'subtasks' => [['name' => 'set up shared pieces', 'description' => 'Do the shared setup.', 'position' => 1]],
        ]],
    ]);

    app(ExecutionService::class)->dispatchTaskGeneration($story);

    $task = $story->fresh()->currentPlanTasks()->sole();
    expect($task->acceptance_criterion_id)->toBeNull()
        ->and($task->subtasks()->count())->toBe(1);
});

test('agent failure marks the AgentRun failed with error message', function () {
    $story = Story::factory()->create();
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);

    TasksGenerator::fake(function () {
        throw new RuntimeException('agent down');
    });

    try {
        app(ExecutionService::class)->dispatchTaskGeneration($story);
    } catch (Throwable $e) {
        // sync queue rethrows
    }

    $run = AgentRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error_message)->toContain('agent down');
});

test('regeneration on an Approved story keeps story approval intact but reopens current plan approval', function () {
    $story = Story::factory()->create(['status' => StoryStatus::Approved, 'revision' => 1]);
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);
    $story = $story->fresh();
    $approver = User::factory()->create();
    StoryApproval::create([
        'story_id' => $story->id,
        'story_revision' => $story->revision,
        'approver_id' => $approver->id,
        'decision' => ApprovalDecision::Approve->value,
    ]);
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $story->feature->project_id,
        'required_approvals' => 1,
    ]);
    $revisionBefore = $story->revision;

    TasksGenerator::fake(fn () => [
        'summary' => 'plan',
        'tasks' => [[
            'name' => 't', 'description' => 'd', 'position' => 1,
            'acceptance_criterion_position' => 1,
            'subtasks' => [['name' => 's', 'description' => 'sd', 'position' => 1]],
        ]],
    ]);

    app(ExecutionService::class)->dispatchTaskGeneration($story);

    $fresh = $story->fresh()->load('currentPlan');
    expect($fresh->status)->toBe(StoryStatus::Approved)
        ->and($fresh->revision)->toBe($revisionBefore)
        ->and($fresh->currentPlan)->not->toBeNull()
        ->and($fresh->currentPlan->status)->toBe(PlanStatus::PendingApproval)
        ->and($fresh->currentPlan->revision)->toBe(1);
});
