<?php

use App\Enums\ProjectStatus;
use App\Enums\TeamRole;
use App\Mcp\Tools\ReorderFeaturesTool;
use App\Mcp\Tools\ReorderStoriesTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ordering\PositionReorderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

function orderingScene(TeamRole $role = TeamRole::Admin): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Demo']);

    return compact('user', 'project');
}

test('update-project: admin can rename and update description', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $this->actingAs($user);

    $response = app(UpdateProjectTool::class)->handle(new Request([
        'project_id' => $project->id,
        'name' => 'Renamed',
        'description' => 'Now with description',
    ]));

    expect($response->isError())->toBeFalse();
    $project->refresh();
    expect($project->name)->toBe('Renamed');
    expect($project->description)->toBe('Now with description');
});

test('update-project: status can be cycled', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $this->actingAs($user);

    $response = app(UpdateProjectTool::class)->handle(new Request([
        'project_id' => $project->id,
        'status' => ProjectStatus::Archived->value,
    ]));

    expect($response->isError())->toBeFalse();
    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('update-project: member without approve rights is rejected', function () {
    ['user' => $user, 'project' => $project] = orderingScene(TeamRole::Member);
    $this->actingAs($user);

    $response = app(UpdateProjectTool::class)->handle(new Request([
        'project_id' => $project->id,
        'name' => 'Should not save',
    ]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('approve rights');
});

test('update-project: empty payload is rejected', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $this->actingAs($user);

    $response = app(UpdateProjectTool::class)->handle(new Request([
        'project_id' => $project->id,
    ]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('at least one');
});

test('reorder-features: admin reorders features within a project', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $a = Feature::factory()->for($project)->create(['name' => 'Alpha']);
    $b = Feature::factory()->for($project)->create(['name' => 'Bravo']);
    $c = Feature::factory()->for($project)->create(['name' => 'Charlie']);
    $this->actingAs($user);

    $response = app(ReorderFeaturesTool::class)->handle(new Request([
        'project_id' => $project->id,
        'ordered_ids' => [$c->id, $a->id, $b->id],
    ]), app(PositionReorderer::class));

    expect($response->isError())->toBeFalse();
    expect($c->fresh()->position)->toBe(1);
    expect($a->fresh()->position)->toBe(2);
    expect($b->fresh()->position)->toBe(3);
});

test('reorder-features: incomplete payload is rejected with a clear error', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $a = Feature::factory()->for($project)->create();
    $b = Feature::factory()->for($project)->create();
    Feature::factory()->for($project)->create();
    $this->actingAs($user);

    $response = app(ReorderFeaturesTool::class)->handle(new Request([
        'project_id' => $project->id,
        'ordered_ids' => [$b->id, $a->id],
    ]), app(PositionReorderer::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('every feature');
});

test('reorder-features: foreign feature ids are rejected as incomplete', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $a = Feature::factory()->for($project)->create();
    $b = Feature::factory()->for($project)->create();
    $c = Feature::factory()->for($project)->create();
    $otherProject = Project::factory()->for($project->team)->create();
    $foreign = Feature::factory()->for($otherProject)->create();
    $this->actingAs($user);

    $response = app(ReorderFeaturesTool::class)->handle(new Request([
        'project_id' => $project->id,
        'ordered_ids' => [$b->id, $foreign->id, $a->id],
    ]), app(PositionReorderer::class));

    expect($response->isError())->toBeTrue();
    expect($a->fresh()->position)->toBe(1);
    expect($b->fresh()->position)->toBe(2);
    expect($c->fresh()->position)->toBe(3);
    expect($foreign->fresh()->position)->toBe(1);
});

test('reorder-features: member without approve rights is rejected', function () {
    ['user' => $user, 'project' => $project] = orderingScene(TeamRole::Member);
    $a = Feature::factory()->for($project)->create();
    $b = Feature::factory()->for($project)->create();
    $this->actingAs($user);

    $response = app(ReorderFeaturesTool::class)->handle(new Request([
        'project_id' => $project->id,
        'ordered_ids' => [$b->id, $a->id],
    ]), app(PositionReorderer::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('approve rights');
});

test('reorder-stories: admin reorders stories within a feature', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $feature = Feature::factory()->for($project)->create();
    $a = Story::factory()->for($feature)->create();
    $b = Story::factory()->for($feature)->create();
    $c = Story::factory()->for($feature)->create();
    $this->actingAs($user);

    $response = app(ReorderStoriesTool::class)->handle(new Request([
        'feature_id' => $feature->id,
        'ordered_ids' => [$c->id, $a->id, $b->id],
    ]), app(PositionReorderer::class));

    expect($response->isError())->toBeFalse();
    expect($c->fresh()->position)->toBe(1);
    expect($a->fresh()->position)->toBe(2);
    expect($b->fresh()->position)->toBe(3);
});

test('reorder-stories: incomplete payload is rejected with a clear error', function () {
    ['user' => $user, 'project' => $project] = orderingScene();
    $feature = Feature::factory()->for($project)->create();
    $a = Story::factory()->for($feature)->create();
    $b = Story::factory()->for($feature)->create();
    Story::factory()->for($feature)->create();
    $this->actingAs($user);

    $response = app(ReorderStoriesTool::class)->handle(new Request([
        'feature_id' => $feature->id,
        'ordered_ids' => [$b->id, $a->id],
    ]), app(PositionReorderer::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('every story');
});

test('reorder-stories: member without approve rights is rejected', function () {
    ['user' => $user, 'project' => $project] = orderingScene(TeamRole::Member);
    $feature = Feature::factory()->for($project)->create();
    $a = Story::factory()->for($feature)->create();
    $b = Story::factory()->for($feature)->create();
    $this->actingAs($user);

    $response = app(ReorderStoriesTool::class)->handle(new Request([
        'feature_id' => $feature->id,
        'ordered_ids' => [$b->id, $a->id],
    ]), app(PositionReorderer::class));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('approve rights');
});
