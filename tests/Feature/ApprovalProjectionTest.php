<?php

use App\Enums\ApprovalDecision;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\StoryApproval;
use App\Models\User;
use App\Services\Approvals\ApprovalProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function recordStoryDecision($story, User $user, ApprovalDecision $decision, ?int $revision = null): StoryApproval
{
    return StoryApproval::create([
        'story_id' => $story->id,
        'story_revision' => $revision ?? ($story->revision ?? 1),
        'approver_id' => $user->id,
        'decision' => $decision,
        'notes' => null,
    ]);
}

function recordPlanDecision(Plan $plan, User $user, ApprovalDecision $decision, ?int $revision = null): PlanApproval
{
    return PlanApproval::create([
        'plan_id' => $plan->id,
        'plan_revision' => $revision ?? ($plan->revision ?? 1),
        'approver_id' => $user->id,
        'decision' => $decision,
        'notes' => null,
    ]);
}

test('effectiveStoryApprovals folds approve/revoke per approver', function () {
    $story = makeStory();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    recordStoryDecision($story, $alice, ApprovalDecision::Approve);
    recordStoryDecision($story, $bob, ApprovalDecision::Approve);
    recordStoryDecision($story, $alice, ApprovalDecision::Revoke);

    $effective = app(ApprovalProjection::class)->effectiveStoryApprovals($story->fresh('approvals'));

    expect($effective)->toHaveCount(1)
        ->and($effective)->toHaveKey($bob->id)
        ->and($effective)->not->toHaveKey($alice->id);
});

test('effectiveStoryApprovals re-approval after revoke counts', function () {
    $story = makeStory();
    $alice = User::factory()->create();

    recordStoryDecision($story, $alice, ApprovalDecision::Approve);
    recordStoryDecision($story, $alice, ApprovalDecision::Revoke);
    recordStoryDecision($story, $alice, ApprovalDecision::Approve);

    $effective = app(ApprovalProjection::class)->effectiveStoryApprovals($story->fresh('approvals'));

    expect($effective)->toHaveKey($alice->id);
});

test('effectiveStoryApprovals only considers the current revision', function () {
    $story = makeStory();
    $story->forceFill(['revision' => 3])->save();
    $alice = User::factory()->create();

    recordStoryDecision($story, $alice, ApprovalDecision::Approve, revision: 2);

    $effective = app(ApprovalProjection::class)->effectiveStoryApprovals($story->fresh('approvals'));

    expect($effective)->toBeEmpty();
});

test('currentRevisionStoryApprovals filters to current revision and orders oldest first', function () {
    $story = makeStory();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Carbon\Carbon::setTestNow(now()->subMinute());
    $earlier = recordStoryDecision($story, $alice, ApprovalDecision::Approve);
    Carbon\Carbon::setTestNow(now()->addMinute());
    $later = recordStoryDecision($story, $bob, ApprovalDecision::Approve);
    Carbon\Carbon::setTestNow();

    $current = app(ApprovalProjection::class)->currentRevisionStoryApprovals($story->fresh('approvals'));

    expect($current)->toHaveCount(2)
        ->and($current->first()->id)->toBe($earlier->id)
        ->and($current->last()->id)->toBe($later->id);
});

test('priorRevisionStoryApprovals returns prior revisions newest first', function () {
    $story = makeStory();
    $story->forceFill(['revision' => 3])->save();
    $alice = User::factory()->create();

    Carbon\Carbon::setTestNow(now()->subDay());
    $rev1 = recordStoryDecision($story, $alice, ApprovalDecision::Approve, revision: 1);
    Carbon\Carbon::setTestNow(now()->addDay());
    $rev2 = recordStoryDecision($story, $alice, ApprovalDecision::Approve, revision: 2);
    Carbon\Carbon::setTestNow();

    $prior = app(ApprovalProjection::class)->priorRevisionStoryApprovals($story->fresh('approvals'));

    expect($prior)->toHaveCount(2)
        ->and($prior->first()->id)->toBe($rev2->id)
        ->and($prior->last()->id)->toBe($rev1->id);
});

test('ChangesRequested clears effective approvers in the same revision', function () {
    $story = makeStory();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    recordStoryDecision($story, $alice, ApprovalDecision::Approve);
    recordStoryDecision($story, $bob, ApprovalDecision::ChangesRequested);

    expect(app(ApprovalProjection::class)->effectiveStoryApprovals($story->fresh('approvals')))->toBeEmpty();
});

test('ChangesRequested followed by Approve rebuilds effective set', function () {
    $story = makeStory();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    recordStoryDecision($story, $alice, ApprovalDecision::Approve);
    recordStoryDecision($story, $bob, ApprovalDecision::ChangesRequested);
    recordStoryDecision($story, $alice, ApprovalDecision::Approve);

    expect(app(ApprovalProjection::class)->effectiveStoryApprovals($story->fresh('approvals')))
        ->toHaveKey($alice->id);
});

test('Reject is terminal: no approver counts as effective', function () {
    $story = makeStory();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    recordStoryDecision($story, $alice, ApprovalDecision::Approve);
    recordStoryDecision($story, $bob, ApprovalDecision::Approve);
    recordStoryDecision($story, $bob, ApprovalDecision::Reject);

    expect(app(ApprovalProjection::class)->effectiveStoryApprovals($story->fresh('approvals')))->toBeEmpty();
});

test('same-second decisions are ordered deterministically by id (revoke after approve)', function () {
    $story = makeStory();
    $alice = User::factory()->create();

    Carbon\Carbon::setTestNow('2026-05-10 12:00:00');
    recordStoryDecision($story, $alice, ApprovalDecision::Approve);
    recordStoryDecision($story, $alice, ApprovalDecision::Revoke);
    Carbon\Carbon::setTestNow();

    expect(app(ApprovalProjection::class)->effectiveStoryApprovals($story->fresh('approvals')))->toBeEmpty();
});

test('plan projections mirror story projections via plan_revision', function () {
    $story = makeStory();
    $plan = Plan::factory()->for($story)->create(['revision' => 2]);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    recordPlanDecision($plan, $alice, ApprovalDecision::Approve, revision: 1);
    recordPlanDecision($plan, $alice, ApprovalDecision::Approve, revision: 2);
    recordPlanDecision($plan, $bob, ApprovalDecision::Approve, revision: 2);
    recordPlanDecision($plan, $alice, ApprovalDecision::Revoke, revision: 2);

    $projection = app(ApprovalProjection::class);
    $effective = $projection->effectivePlanApprovals($plan->fresh('approvals'));
    expect($effective)->toHaveCount(1)->toHaveKey($bob->id);

    expect($projection->currentRevisionPlanApprovals($plan->fresh('approvals')))->toHaveCount(3);
    expect($projection->priorRevisionPlanApprovals($plan->fresh('approvals')))->toHaveCount(1);
});
