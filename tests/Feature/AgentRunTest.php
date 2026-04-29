<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteTaskJob;
use App\Jobs\GeneratePlanJob;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\StoryApproval;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionService;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([GeneratePlanJob::class, ExecuteTaskJob::class]);
});

function approvedPlanWithTasks(int $taskCount = 2): Plan
{
    $story = makeStory();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $story->feature->project_id,
        'required_approvals' => 0,
    ]);

    $plan = Plan::factory()->for($story)->create();
    Task::factory()->count($taskCount)->for($plan)->state(new Sequence(
        fn ($s) => ['name' => 'task-'.$s->index, 'position' => $s->index]
    ))->create();

    $plan->submitForApproval();

    return $plan->fresh();
}

test('plan generation run targets a Story', function () {
    $story = makeStory();
    $approval = StoryApproval::create([
        'story_id' => $story->id,
        'story_revision' => 1,
        'approver_id' => User::factory()->create()->id,
        'decision' => ApprovalDecision::Approve->value,
    ]);

    $run = app(ExecutionService::class)->dispatchPlanGeneration($story, $approval);

    expect($run->status)->toBe(AgentRunStatus::Queued)
        ->and($run->runnable->is($story))->toBeTrue()
        ->and($run->authorizingApproval->is($approval))->toBeTrue()
        ->and($story->agentRuns)->toHaveCount(1);

    Bus::assertDispatched(GeneratePlanJob::class);
});

test('task execution run targets a Task and links to plan approval', function () {
    $task = Task::factory()->create();
    $plan = $task->plan;
    $approval = PlanApproval::create([
        'plan_id' => $plan->id,
        'approver_id' => User::factory()->create()->id,
        'decision' => ApprovalDecision::Approve->value,
    ]);

    $run = app(ExecutionService::class)->dispatchTaskExecution($task, $approval);

    expect($run->status)->toBe(AgentRunStatus::Queued)
        ->and($run->runnable->is($task))->toBeTrue()
        ->and($run->authorizingApproval->is($approval))->toBeTrue();
});

test('lifecycle queued -> running -> succeeded marks task done', function () {
    $task = Task::factory()->create();
    $service = app(ExecutionService::class);
    $run = $service->dispatchTaskExecution($task);

    $service->markRunning($run);
    expect($run->fresh()->status)->toBe(AgentRunStatus::Running)
        ->and($run->fresh()->started_at)->not->toBeNull();

    $service->markSucceeded($run, ['ok' => true], 'diff text');
    expect($run->fresh()->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->fresh()->finished_at)->not->toBeNull()
        ->and($run->fresh()->output)->toBe(['ok' => true])
        ->and($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('plan flips to Done when all tasks succeed', function () {
    $plan = approvedPlanWithTasks(2);
    $service = app(ExecutionService::class);

    $service->startPlanExecution($plan);
    expect($plan->fresh()->status)->toBe(PlanStatus::Executing);

    foreach ($plan->tasks as $task) {
        $run = $task->agentRuns()->first() ?? $service->dispatchTaskExecution($task);
        $service->markSucceeded($run);
    }

    expect($plan->fresh()->status)->toBe(PlanStatus::Done);
});

test('failure blocks the task and does not advance the plan', function () {
    $plan = approvedPlanWithTasks(1);
    $service = app(ExecutionService::class);
    $service->startPlanExecution($plan);

    $task = $plan->tasks()->first();
    $run = $task->agentRuns()->first();

    $service->markFailed($run, 'oops');

    expect($run->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and($run->fresh()->error_message)->toBe('oops')
        ->and($task->fresh()->status)->toBe(TaskStatus::Blocked)
        ->and($plan->fresh()->status)->toBe(PlanStatus::Executing);
});

test('startPlanExecution refuses non-Approved plans', function () {
    $plan = Plan::factory()->create();
    expect(fn () => app(ExecutionService::class)->startPlanExecution($plan))
        ->toThrow(RuntimeException::class, 'Approved');
});

test('dependent tasks dispatch only after their dependencies succeed', function () {
    $plan = approvedPlanWithTasks(0);
    $a = Task::factory()->for($plan)->create(['name' => 'a']);
    $b = Task::factory()->for($plan)->create(['name' => 'b']);
    $b->addDependency($a);

    $service = app(ExecutionService::class);
    $service->startPlanExecution($plan);

    expect(AgentRun::where('runnable_id', $a->id)->count())->toBe(1)
        ->and(AgentRun::where('runnable_id', $b->id)->count())->toBe(0);

    $runA = $a->agentRuns()->first();
    $service->markSucceeded($runA);

    expect(AgentRun::where('runnable_id', $b->id)->count())->toBe(1);
});

test('agent run deletion is blocked', function () {
    $run = AgentRun::factory()->create();

    expect(fn () => $run->delete())
        ->toThrow(RuntimeException::class, 'immutable');
});

test('agent run output round-trips as array', function () {
    $run = AgentRun::factory()->create(['output' => ['files' => ['a.php', 'b.php']]]);

    expect($run->fresh()->output)->toBe(['files' => ['a.php', 'b.php']]);
});
