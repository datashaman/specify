<?php

use App\Enums\TeamRole;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function projectShowScene(TeamRole $role = TeamRole::Admin): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);

    return compact('user', 'project');
}

test('admin can create a feature on the project page', function () {
    ['user' => $user, 'project' => $project] = projectShowScene();
    $this->actingAs($user);

    Livewire::test('pages::projects.show', ['project' => $project->id])
        ->set('newFeatureName', 'Authoring')
        ->set('newFeatureDescription', 'Story creation flows')
        ->call('createFeature');

    expect($project->fresh()->features()->where('name', 'Authoring')->exists())->toBeTrue();
});

test('member cannot create a feature', function () {
    ['user' => $user, 'project' => $project] = projectShowScene(TeamRole::Member);
    $this->actingAs($user);

    Livewire::test('pages::projects.show', ['project' => $project->id])
        ->set('newFeatureName', 'NoEntry')
        ->call('createFeature')
        ->assertStatus(403);

    expect($project->fresh()->features()->count())->toBe(0);
});

test('out-of-scope project 404s', function () {
    ['user' => $user] = projectShowScene();
    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::projects.show', ['project' => $otherProject->id])
        ->assertStatus(404);
});

test('lists existing features with story counts', function () {
    ['user' => $user, 'project' => $project] = projectShowScene();
    $feature = Feature::factory()->for($project)->create(['name' => 'Visible Feature']);

    $this->actingAs($user);

    Livewire::test('pages::projects.show', ['project' => $project->id])
        ->assertSee('Visible Feature');
});
