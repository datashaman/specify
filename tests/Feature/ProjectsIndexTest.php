<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

test('lists accessible projects with feature, repo, story counts', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    Project::factory()->for($team)->create(['name' => 'Visible Project']);

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    Project::factory()->for($otherTeam)->create(['name' => 'Hidden Project']);

    $this->actingAs($user);

    Livewire::test('pages::projects.index')
        ->assertSee('Visible Project')
        ->assertDontSee('Hidden Project');
});

test('redirects guests', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('admin can delete a project from the projects index', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Admin);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create(['name' => 'Disposable Project']);

    $user->forceFill(['current_project_id' => $project->id])->save();

    $this->actingAs($user);

    Livewire::test('pages::projects.index')
        ->call('confirmDelete', $project->id)
        ->call('deleteProject', $project->id);

    expect(Project::find($project->id))->toBeNull();
    expect($user->fresh()->current_project_id)->toBeNull();
});

test('member cannot delete a project from the projects index', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Member);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create();

    $this->actingAs($user);

    Livewire::test('pages::projects.index')
        ->call('confirmDelete', $project->id)
        ->assertStatus(403);

    expect(Project::find($project->id))->not->toBeNull();
});
