<?php

use App\Mcp\Tools\CreatePlanTool;
use App\Mcp\Tools\SetCurrentPlanTool;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Plans\CurrentPlanSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

function currentPlanScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();

    return compact('user', 'story');
}

test('create plan tool can set the new plan as current', function () {
    ['user' => $user, 'story' => $story] = currentPlanScene();
    $this->actingAs($user);

    $response = app(CreatePlanTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
        'name' => 'Implementation option A',
        'set_current' => true,
    ]), app(CurrentPlanSelector::class));

    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->isError())->toBeFalse()
        ->and($payload['is_current'])->toBeTrue()
        ->and($story->fresh()->current_plan_id)->toBe($payload['id']);
});

test('set current plan tool rejects a plan from another story', function () {
    ['user' => $user, 'story' => $story] = currentPlanScene();
    $otherStory = Story::factory()->for($story->feature)->create();
    $otherPlan = Plan::factory()->for($otherStory)->create();
    $this->actingAs($user);

    $response = app(SetCurrentPlanTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
        'plan_id' => $otherPlan->getKey(),
    ]), app(CurrentPlanSelector::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('Plan does not belong to this story')
        ->and($story->fresh()->current_plan_id)->toBeNull();
});
