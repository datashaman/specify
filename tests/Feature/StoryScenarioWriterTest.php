<?php

use App\Enums\StoryStatus;
use App\Mcp\Tools\CreateScenarioTool;
use App\Mcp\Tools\UpdateScenarioTool;
use App\Models\AcceptanceCriterion;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Scenario;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Stories\ScenarioWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

function scenarioWriterScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved, 'revision' => 1]);
    $criterion = AcceptanceCriterion::withoutEvents(
        fn () => AcceptanceCriterion::factory()->for($story)->create(['position' => 1])
    );

    return compact('user', 'story', 'criterion');
}

test('create scenario tool records one story content revision', function () {
    ['user' => $user, 'story' => $story, 'criterion' => $criterion] = scenarioWriterScene();
    $this->actingAs($user);

    $response = app(CreateScenarioTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
        'acceptance_criterion_id' => $criterion->getKey(),
        'name' => 'CSV export includes headers',
    ]), app(ScenarioWriter::class));

    expect($response->isError())->toBeFalse()
        ->and($story->fresh()->revision)->toBe(2)
        ->and($story->scenarios()->count())->toBe(1);
});

test('update scenario tool records one story content revision', function () {
    ['user' => $user, 'story' => $story, 'criterion' => $criterion] = scenarioWriterScene();
    $scenario = Scenario::withoutEvents(fn () => Scenario::factory()->for($story)->create([
        'acceptance_criterion_id' => $criterion->getKey(),
        'position' => 1,
        'name' => 'Old scenario',
    ]));
    $this->actingAs($user);

    $response = app(UpdateScenarioTool::class)->handle(new Request([
        'scenario_id' => $scenario->getKey(),
        'name' => 'Updated scenario',
        'given_text' => 'Given an export exists',
    ]), app(ScenarioWriter::class));

    $fresh = $story->fresh();

    expect($response->isError())->toBeFalse()
        ->and($fresh->revision)->toBe(2)
        ->and($fresh->scenarios()->sole()->name)->toBe('Updated scenario');
});

test('update scenario tool does not revise the story for a no-op update', function () {
    ['user' => $user, 'story' => $story, 'criterion' => $criterion] = scenarioWriterScene();
    $scenario = Scenario::withoutEvents(fn () => Scenario::factory()->for($story)->create([
        'acceptance_criterion_id' => $criterion->getKey(),
        'position' => 1,
        'name' => 'Existing scenario',
    ]));
    $this->actingAs($user);

    $response = app(UpdateScenarioTool::class)->handle(new Request([
        'scenario_id' => $scenario->getKey(),
        'name' => 'Existing scenario',
        'acceptance_criterion_id' => $criterion->getKey(),
    ]), app(ScenarioWriter::class));

    expect($response->isError())->toBeFalse()
        ->and($story->fresh()->revision)->toBe(1);
});

test('update scenario tool rejects acceptance criteria from another story', function () {
    ['user' => $user, 'story' => $story, 'criterion' => $criterion] = scenarioWriterScene();
    $scenario = Scenario::withoutEvents(fn () => Scenario::factory()->for($story)->create([
        'acceptance_criterion_id' => $criterion->getKey(),
        'position' => 1,
        'name' => 'Scenario',
    ]));
    $otherCriterion = AcceptanceCriterion::factory()->create();
    $this->actingAs($user);

    $response = app(UpdateScenarioTool::class)->handle(new Request([
        'scenario_id' => $scenario->getKey(),
        'acceptance_criterion_id' => $otherCriterion->getKey(),
    ]), app(ScenarioWriter::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('does not belong to this story')
        ->and($story->fresh()->revision)->toBe(1)
        ->and($scenario->fresh()->acceptance_criterion_id)->toBe($criterion->getKey());
});

test('scenario writer rejects acceptance criteria from another story', function () {
    ['user' => $user, 'story' => $story] = scenarioWriterScene();
    $otherCriterion = AcceptanceCriterion::factory()->create();
    $this->actingAs($user);

    $response = app(CreateScenarioTool::class)->handle(new Request([
        'story_id' => $story->getKey(),
        'acceptance_criterion_id' => $otherCriterion->getKey(),
        'name' => 'Wrong criterion',
    ]), app(ScenarioWriter::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('does not belong to this story')
        ->and($story->fresh()->revision)->toBe(1);
});
