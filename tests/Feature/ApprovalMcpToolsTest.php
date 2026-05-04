<?php

use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Enums\TeamRole;
use App\Mcp\Tools\ApprovePlanTool;
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
