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
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeStory(): Story
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    return Story::factory()->for($feature)->create(['status' => StoryStatus::Draft]);
}

function policyFor(string $scope, int $id, array $attrs): ApprovalPolicy
{
    return ApprovalPolicy::create(array_merge([
        'scope_type' => $scope,
        'scope_id' => $id,
    ], $attrs));
}

test('default policy: required=0, story sits at Draft until submitted, then auto-Approved', function () {
    $story = makeStory();

    expect($story->status)->toBe(StoryStatus::Draft);

    $story->submitForApproval();

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('required=1: story sits at PendingApproval until one Approve recorded', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);

    $story->submitForApproval();
    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);

    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($story, $approver, ApprovalDecision::Approve);

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('required=2: two distinct approvers needed', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 2]);
    $story->submitForApproval();

    $a = User::factory()->create();
    $b = User::factory()->create();

    app(ApprovalService::class)->recordDecision($story, $a, ApprovalDecision::Approve);
    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);

    app(ApprovalService::class)->recordDecision($story, $a, ApprovalDecision::Approve);
    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);

    app(ApprovalService::class)->recordDecision($story, $b, ApprovalDecision::Approve);
    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('self-approval is rejected by default', function () {
    $creator = User::factory()->create();
    $story = makeStory();
    $story->update(['created_by_id' => $creator->id]);
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);
    $story->submitForApproval();

    expect(fn () => app(ApprovalService::class)->recordDecision($story, $creator, ApprovalDecision::Approve))
        ->toThrow(InvalidArgumentException::class, 'Self-approval');
});

test('self-approval allowed when policy permits', function () {
    $creator = User::factory()->create();
    $story = makeStory();
    $story->update(['created_by_id' => $creator->id]);
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, [
        'required_approvals' => 1,
        'allow_self_approval' => true,
    ]);
    $story->submitForApproval();

    app(ApprovalService::class)->recordDecision($story, $creator, ApprovalDecision::Approve);

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('Revoke after Approve drops effective count and status', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);
    $story->submitForApproval();
    $approver = User::factory()->create();

    app(ApprovalService::class)->recordDecision($story, $approver, ApprovalDecision::Approve);
    expect($story->fresh()->status)->toBe(StoryStatus::Approved);

    app(ApprovalService::class)->recordDecision($story, $approver, ApprovalDecision::Revoke);
    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);
});

test('ChangesRequested moves status; subsequent edit + new Approve resolves', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);
    $story->submitForApproval();
    $approver = User::factory()->create();

    app(ApprovalService::class)->recordDecision($story, $approver, ApprovalDecision::ChangesRequested);
    expect($story->fresh()->status)->toBe(StoryStatus::ChangesRequested);

    $story->update(['description' => 'revised description']);
    app(ApprovalService::class)->recordDecision($story->fresh(), $approver, ApprovalDecision::Approve);
    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('Reject is terminal — further decisions raise', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);
    $story->submitForApproval();
    $approver = User::factory()->create();

    app(ApprovalService::class)->recordDecision($story, $approver, ApprovalDecision::Reject);
    expect($story->fresh()->status)->toBe(StoryStatus::Rejected);

    expect(fn () => app(ApprovalService::class)->recordDecision($story->fresh(), $approver, ApprovalDecision::Approve))
        ->toThrow(RuntimeException::class);
});

test('editing acceptance criteria invalidates prior approvals', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);
    AcceptanceCriterion::factory()->for($story)->create();
    $story->submitForApproval();
    $approver = User::factory()->create();

    app(ApprovalService::class)->recordDecision($story->fresh(), $approver, ApprovalDecision::Approve);
    expect($story->fresh()->status)->toBe(StoryStatus::Approved);

    AcceptanceCriterion::factory()->for($story)->create();

    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);
});

test('plan regeneration starts fresh approval cycle', function () {
    $story = makeStory();
    $project = $story->feature->project;
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $project->id, ['required_approvals' => 1]);

    $planV1 = Plan::factory()->for($story)->create(['version' => 1]);
    $planV1->submitForApproval();
    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($planV1, $approver, ApprovalDecision::Approve);
    expect($planV1->fresh()->status)->toBe(PlanStatus::Executing);

    $planV2 = Plan::factory()->for($story)->create(['version' => 2]);
    expect($planV2->status)->toBe(PlanStatus::Draft);

    $planV2->submitForApproval();
    expect($planV2->fresh()->status)->toBe(PlanStatus::PendingApproval);
});

test('plan policy resolves Project then Workspace, ignoring Story-level policy', function () {
    $story = makeStory();
    $project = $story->feature->project;
    policyFor(ApprovalPolicy::SCOPE_STORY, $story->id, ['required_approvals' => 5]);
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $project->id, ['required_approvals' => 1]);

    $plan = Plan::factory()->for($story)->create();
    $plan->submitForApproval();
    expect($plan->fresh()->status)->toBe(PlanStatus::PendingApproval);

    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($plan, $approver, ApprovalDecision::Approve);
    expect($plan->fresh()->status)->toBe(PlanStatus::Executing);
});

test('approval rows are immutable', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 1]);
    $story->submitForApproval();
    $approver = User::factory()->create();
    $approval = app(ApprovalService::class)->recordDecision($story, $approver, ApprovalDecision::Approve);

    expect(fn () => $approval->update(['notes' => 'changed']))
        ->toThrow(RuntimeException::class, 'immutable');

    expect(fn () => $approval->delete())
        ->toThrow(RuntimeException::class, 'immutable');
});

test('Story-level policy overrides Project-level policy', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, ['required_approvals' => 5]);
    policyFor(ApprovalPolicy::SCOPE_STORY, $story->id, ['required_approvals' => 1]);

    $story->submitForApproval();
    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($story, $approver, ApprovalDecision::Approve);

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('auto_approve short-circuits required_approvals', function () {
    $story = makeStory();
    policyFor(ApprovalPolicy::SCOPE_PROJECT, $story->feature->project_id, [
        'required_approvals' => 5,
        'auto_approve' => true,
    ]);

    $story->submitForApproval();

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});
