<?php

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePlan(): Plan
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    $criterion = AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);
    $task = Task::factory()->for($story)->create([
        'position' => 1,
        'acceptance_criterion_id' => $criterion->id,
    ]);

    $story->forceFill(['current_plan_id' => $task->plan_id])->save();

    return Plan::query()->findOrFail($task->plan_id);
}

function policyForPlan(string $scope, int $id, array $attrs): ApprovalPolicy
{
    return ApprovalPolicy::create(array_merge([
        'scope_type' => $scope,
        'scope_id' => $id,
    ], $attrs));
}

test('default plan policy: plan sits at Draft until submitted, then auto-Approved', function () {
    $plan = makePlan();

    expect($plan->status)->toBe(PlanStatus::Draft);

    $plan->submitForApproval();

    expect($plan->fresh()->status)->toBe(PlanStatus::Approved);
});

test('required=1: plan sits at PendingApproval until one Approve recorded', function () {
    $plan = makePlan();
    policyForPlan(ApprovalPolicy::SCOPE_PROJECT, $plan->story->feature->project_id, ['required_approvals' => 1]);

    $plan->submitForApproval();
    expect($plan->fresh()->status)->toBe(PlanStatus::PendingApproval);

    $approver = User::factory()->create();
    app(ApprovalService::class)->recordPlanDecision($plan->fresh(), $approver, ApprovalDecision::Approve);

    expect($plan->fresh()->status)->toBe(PlanStatus::Approved);
});

test('self-approval is rejected by default for plans', function () {
    $creator = User::factory()->create();
    $plan = makePlan();
    $plan->story->update(['created_by_id' => $creator->id]);
    policyForPlan(ApprovalPolicy::SCOPE_PROJECT, $plan->story->feature->project_id, ['required_approvals' => 1]);
    $plan->submitForApproval();

    expect(fn () => app(ApprovalService::class)->recordPlanDecision($plan->fresh(), $creator, ApprovalDecision::Approve))
        ->toThrow(InvalidArgumentException::class, 'Self-approval');
});

test('editing the current plan invalidates prior plan approvals but leaves story approved', function () {
    $plan = makePlan();
    $story = $plan->story;
    $story->silentlyForceFill(['status' => StoryStatus::Approved, 'revision' => 1]);
    policyForPlan(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);
    $approver = User::factory()->create();

    $plan->submitForApproval();
    app(ApprovalService::class)->recordPlanDecision($plan->fresh(), $approver, ApprovalDecision::Approve);

    expect($plan->fresh()->status)->toBe(PlanStatus::Approved)
        ->and($story->fresh()->status)->toBe(StoryStatus::Approved);

    $task = $story->tasks()->firstOrFail();
    $task->update(['name' => 'renamed task']);
    $task->plan->reopenForApproval();

    expect($plan->fresh()->status)->toBe(PlanStatus::PendingApproval)
        ->and($plan->fresh()->revision)->toBe(2)
        ->and($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('draft plans cannot be pre-approved before submission', function () {
    $plan = makePlan();
    $approver = User::factory()->create();

    expect(fn () => app(ApprovalService::class)->recordPlanDecision($plan->fresh(), $approver, ApprovalDecision::Approve))
        ->toThrow(RuntimeException::class, 'submitted');
});

test('plan approval rows are immutable', function () {
    $plan = makePlan();
    policyForPlan(ApprovalPolicy::SCOPE_PROJECT, $plan->story->feature->project_id, ['required_approvals' => 1]);
    $plan->submitForApproval();
    $approver = User::factory()->create();
    $approval = app(ApprovalService::class)->recordPlanDecision($plan->fresh(), $approver, ApprovalDecision::Approve);

    expect(fn () => $approval->update(['notes' => 'changed']))
        ->toThrow(RuntimeException::class, 'immutable');

    expect(fn () => $approval->delete())
        ->toThrow(RuntimeException::class, 'immutable');
});
