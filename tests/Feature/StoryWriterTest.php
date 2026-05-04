<?php

use App\Enums\StoryStatus;
use App\Mcp\Tools\CreateStoryTool;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Stories\StoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

test('create story tool writes initial acceptance criteria without extra story revisions', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    $this->actingAs($user);

    $response = app(CreateStoryTool::class)->handle(new Request([
        'feature_id' => $feature->getKey(),
        'name' => 'Export customer data',
        'acceptance_criteria' => [
            'CSV includes headers.',
            'CSV contains active customers only.',
        ],
    ]), app(StoryWriter::class));

    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->isError())->toBeFalse()
        ->and($payload['revision'])->toBe(1)
        ->and($payload['status'])->toBe(StoryStatus::Draft->value)
        ->and($payload['acceptance_criteria'])->toHaveCount(2)
        ->and($payload['acceptance_criteria'][0]['position'])->toBe(1)
        ->and($payload['acceptance_criteria'][1]['position'])->toBe(2);
});

test('story writer normalizes keyed initial acceptance criteria before assigning positions', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $creator = User::factory()->create();
    $feature = Feature::factory()->for(Project::factory()->for($team))->create();

    $story = app(StoryWriter::class)->create($feature, $creator, [
        'name' => 'Import customer data',
        'acceptance_criteria' => [
            'first' => 'Import accepts CSV files.',
            'second' => 'Import rejects malformed rows.',
        ],
    ]);

    expect($story->fresh()->revision)->toBe(1)
        ->and($story->acceptanceCriteria()->orderBy('position')->pluck('position')->all())
        ->toBe([1, 2]);
});
