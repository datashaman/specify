<?php

use App\Enums\StoryStatus;
use App\Mcp\Tools\AddAcceptanceCriterionTool;
use App\Mcp\Tools\UpdateStoryTool;
use App\Models\AcceptanceCriterion;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Stories\AcceptanceCriteriaWriter;
use App\Services\Stories\StoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

function storyCriteriaScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved, 'revision' => 1]);

    return compact('user', 'story');
}

test('add acceptance criterion tool records one story content revision', function () {
    ['user' => $user, 'story' => $story] = storyCriteriaScene();
    $this->actingAs($user);

    $response = app(AddAcceptanceCriterionTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
        'statement' => 'Exports include a header row.',
    ]), app(AcceptanceCriteriaWriter::class));

    expect($response->isError())->toBeFalse()
        ->and($story->fresh()->revision)->toBe(2)
        ->and($story->acceptanceCriteria()->count())->toBe(1);
});

test('update story tool replaces acceptance criteria as one story content revision', function () {
    ['user' => $user, 'story' => $story] = storyCriteriaScene();

    AcceptanceCriterion::withoutEvents(function () use ($story): void {
        AcceptanceCriterion::factory()->for($story)->create(['position' => 1, 'statement' => 'Old one']);
        AcceptanceCriterion::factory()->for($story)->create(['position' => 2, 'statement' => 'Old two']);
    });

    $this->actingAs($user);

    $response = app(UpdateStoryTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
        'acceptance_criteria' => ['New one', 'New two', 'New three'],
    ]), app(StoryWriter::class));

    expect($response->isError())->toBeFalse()
        ->and($story->fresh()->revision)->toBe(2)
        ->and($story->acceptanceCriteria()->orderBy('position')->pluck('statement')->all())
        ->toBe(['New one', 'New two', 'New three']);
});

test('update story tool combines story fields and acceptance criteria into one story content revision', function () {
    ['user' => $user, 'story' => $story] = storyCriteriaScene();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $story->feature->project_id,
        'required_approvals' => 1,
    ]);

    AcceptanceCriterion::withoutEvents(function () use ($story): void {
        AcceptanceCriterion::factory()->for($story)->create(['position' => 1, 'statement' => 'Old one']);
    });

    $this->actingAs($user);

    $response = app(UpdateStoryTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
        'name' => 'Renamed story',
        'acceptance_criteria' => ['New one'],
    ]), app(StoryWriter::class));

    $fresh = $story->fresh();

    expect($response->isError())->toBeFalse()
        ->and($fresh->name)->toBe('Renamed story')
        ->and($fresh->revision)->toBe(2)
        ->and($fresh->status)->toBe(StoryStatus::PendingApproval)
        ->and($fresh->acceptanceCriteria()->orderBy('position')->pluck('statement')->all())
        ->toBe(['New one']);
});
