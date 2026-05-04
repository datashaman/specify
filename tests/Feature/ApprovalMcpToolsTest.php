<?php

use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Enums\TeamRole;
use App\Mcp\Tools\ApprovePlanTool;
use App\Mcp\Tools\ApproveStoryTool;
use App\Mcp\Tools\RejectPlanTool;
use App\Mcp\Tools\RejectStoryTool;
use App\Mcp\Tools\RequestPlanChangesTool;
use App\Mcp\Tools\RequestStoryChangesTool;
use App\Mcp\Tools\SubmitPlanTool;
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
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

function approvalToolScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Admin);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    $currentTask = Task::factory()->forCurrentPlanOf($story)->create(['position' => 1]);
    $story->forceFill(['current_plan_id' => $currentTask->plan_id])->save();

    return compact('user', 'story');
}

test('submit plan tool rejects non-current plans', function () {
    ['user' => $user, 'story' => $story] = approvalToolScene();
    $otherPlan = Plan::factory()->for($story)->create(['version' => 2]);
    Task::factory()->for($otherPlan)->create(['position' => 1]);
    $this->actingAs($user);

    $response = app(SubmitPlanTool::class)->handle(new Request([
        'plan_id' => $otherPlan->getKey(),
    ]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('current plan');
});

test('approve plan tool rejects non-current plans', function () {
    ['user' => $user, 'story' => $story] = approvalToolScene();
    $otherPlan = Plan::factory()->for($story)->create([
        'version' => 2,
        'status' => PlanStatus::PendingApproval,
    ]);
    $this->actingAs($user);

    $response = app(ApprovePlanTool::class)->handle(new Request([
        'plan_id' => $otherPlan->getKey(),
    ]), app(ApprovalService::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('current plan');
});

test('plan approval tools share current plan resolution and response shape', function (
    string $tool,
    array $extraPayload,
    string $decision,
    string $expectedStatus,
) {
    ['user' => $user, 'story' => $story] = approvalToolScene();
    $plan = Plan::query()->findOrFail($story->current_plan_id);
    $plan->forceFill(['status' => PlanStatus::PendingApproval])->save();
    $this->actingAs($user);

    $response = app($tool)->handle(new Request(array_merge([
        'plan_id' => $plan->getKey(),
    ], $extraPayload)), app(ApprovalService::class));
    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->isError())->toBeFalse()
        ->and($payload)->toHaveKeys(['approval_id', 'plan_id', 'story_id', 'plan_status', 'decision'])
        ->and($payload['plan_id'])->toBe($plan->getKey())
        ->and($payload['story_id'])->toBe($story->getKey())
        ->and($payload['decision'])->toBe($decision)
        ->and($payload['plan_status'])->toBe($expectedStatus);
})->with([
    'approve current plan' => [ApprovePlanTool::class, [], 'approve', PlanStatus::Approved->value],
    'reject current plan' => [RejectPlanTool::class, ['notes' => 'Not acceptable.'], 'reject', PlanStatus::Rejected->value],
    'request current plan changes' => [RequestPlanChangesTool::class, ['notes' => 'Needs smaller subtasks.'], 'changes_requested', PlanStatus::PendingApproval->value],
]);

test('story approval tools share story contract resolution and response shape', function (
    string $tool,
    array $extraPayload,
    string $decision,
    string $expectedStatus,
) {
    ['user' => $user, 'story' => $story] = approvalToolScene();
    $story->forceFill(['status' => StoryStatus::PendingApproval])->save();
    $this->actingAs($user);

    $response = app($tool)->handle(new Request(array_merge([
        'story_id' => $story->getKey(),
    ], $extraPayload)), app(ApprovalService::class));
    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->isError())->toBeFalse()
        ->and($payload)->toHaveKeys(['approval_id', 'story_id', 'story_status', 'decision'])
        ->and($payload['story_id'])->toBe($story->getKey())
        ->and($payload['decision'])->toBe($decision)
        ->and($payload['story_status'])->toBe($expectedStatus);
})->with([
    'approve story contract' => [ApproveStoryTool::class, [], 'approve', StoryStatus::Approved->value],
    'reject story contract' => [RejectStoryTool::class, ['notes' => 'Not acceptable.'], 'reject', StoryStatus::Rejected->value],
    'request story contract changes' => [RequestStoryChangesTool::class, ['notes' => 'Clarify acceptance criteria.'], 'changes_requested', StoryStatus::ChangesRequested->value],
]);

test('shared approval resolver rejects users without approver rights', function () {
    ['story' => $story] = approvalToolScene();
    $member = User::factory()->create();
    $story->feature->project->team->addMember($member, TeamRole::Member);
    $plan = Plan::query()->findOrFail($story->current_plan_id);
    $plan->forceFill(['status' => PlanStatus::PendingApproval])->save();
    $this->actingAs($member);

    $storyResponse = app(ApproveStoryTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
    ]), app(ApprovalService::class));
    $planResponse = app(ApprovePlanTool::class)->handle(new Request([
        'plan_id' => $plan->getKey(),
    ]), app(ApprovalService::class));

    expect($storyResponse->isError())->toBeTrue()
        ->and((string) $storyResponse->content())->toContain('approver rights')
        ->and($planResponse->isError())->toBeTrue()
        ->and((string) $planResponse->content())->toContain('approver rights');
});
