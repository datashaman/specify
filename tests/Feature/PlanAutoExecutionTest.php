<?php

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Jobs\ExecuteTaskJob;
use App\Jobs\GeneratePlanJob;
use App\Models\ApprovalPolicy;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([GeneratePlanJob::class, ExecuteTaskJob::class]);
});

function planAwaitingApproval(int $required, int $taskCount = 2): Plan
{
    $story = makeStory();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $story->feature->project_id,
        'required_approvals' => $required,
    ]);

    $plan = Plan::factory()->for($story)->create();
    for ($i = 0; $i < $taskCount; $i++) {
        Task::factory()->for($plan)->create(['name' => "task-$i", 'position' => $i]);
    }
    $plan->submitForApproval();

    return $plan->fresh();
}

test('approving the threshold flips plan to Executing and dispatches actionable tasks', function () {
    $plan = planAwaitingApproval(required: 1, taskCount: 2);
    expect($plan->status)->toBe(PlanStatus::PendingApproval);

    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($plan, $approver, ApprovalDecision::Approve);

    expect($plan->fresh()->status)->toBe(PlanStatus::Executing);
    Bus::assertDispatchedTimes(ExecuteTaskJob::class, 2);
});

test('a second recompute on an already-executing plan does not double-dispatch', function () {
    $plan = planAwaitingApproval(required: 1, taskCount: 1);
    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($plan, $approver, ApprovalDecision::Approve);
    Bus::assertDispatchedTimes(ExecuteTaskJob::class, 1);

    app(ApprovalService::class)->recompute($plan->fresh());

    expect($plan->fresh()->status)->toBe(PlanStatus::Executing);
    Bus::assertDispatchedTimes(ExecuteTaskJob::class, 1);
});

test('required=0 auto-approves and immediately starts execution', function () {
    $plan = planAwaitingApproval(required: 0, taskCount: 1);

    expect($plan->status)->toBe(PlanStatus::Executing);
    Bus::assertDispatchedTimes(ExecuteTaskJob::class, 1);
});

test('Reject does not start execution', function () {
    $plan = planAwaitingApproval(required: 1, taskCount: 1);
    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($plan, $approver, ApprovalDecision::Reject);

    expect($plan->fresh()->status)->toBe(PlanStatus::Rejected);
    Bus::assertNotDispatched(ExecuteTaskJob::class);
});

test('ChangesRequested does not start execution', function () {
    $plan = planAwaitingApproval(required: 1, taskCount: 1);
    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($plan, $approver, ApprovalDecision::ChangesRequested);

    expect($plan->fresh()->status)->toBe(PlanStatus::ChangesRequested);
    Bus::assertNotDispatched(ExecuteTaskJob::class);
});
