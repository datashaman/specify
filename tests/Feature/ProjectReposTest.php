<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function repoScene(TeamRole $role = TeamRole::Admin): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);

    return compact('user', 'project', 'workspace');
}

test('admins can attach a new repo to a project and it becomes primary by default', function () {
    ['user' => $user, 'project' => $project] = repoScene();
    $this->actingAs($user);

    Livewire::test('pages::projects.repos', ['project' => $project->id])
        ->set('name', 'backend')
        ->set('url', 'https://github.com/datashaman/specify.git')
        ->set('provider', 'github')
        ->set('default_branch', 'main')
        ->set('access_token', 'ghp_xxx')
        ->call('attach');

    $repo = $project->fresh()->repos()->first();
    expect($repo)->not->toBeNull()
        ->and($repo->name)->toBe('backend')
        ->and($repo->access_token)->toBe('ghp_xxx')
        ->and((bool) $repo->pivot->is_primary)->toBeTrue();
});

test('attach with role and is_primary swaps the primary flag', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();

    $existing = Repo::factory()->for($ws)->create(['name' => 'server']);
    $project->attachRepo($existing, role: 'server', primary: true);

    $this->actingAs($user);

    Livewire::test('pages::projects.repos', ['project' => $project->id])
        ->set('name', 'worker')
        ->set('url', 'https://github.com/datashaman/worker.git')
        ->set('provider', 'github')
        ->set('default_branch', 'main')
        ->set('role', 'worker')
        ->set('is_primary', true)
        ->call('attach');

    $primaries = $project->fresh()->repos()->wherePivot('is_primary', true)->get();
    expect($primaries)->toHaveCount(1)
        ->and($primaries->first()->name)->toBe('worker');
});

test('detach removes the repo from the project pivot', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $repo = Repo::factory()->for($ws)->create();
    $project->attachRepo($repo);

    $this->actingAs($user);

    Livewire::test('pages::projects.repos', ['project' => $project->id])
        ->call('detach', $repo->id);

    expect($project->fresh()->repos()->count())->toBe(0);
});

test('Member role cannot attach a repo', function () {
    ['user' => $user, 'project' => $project] = repoScene(TeamRole::Member);

    $this->actingAs($user);

    Livewire::test('pages::projects.repos', ['project' => $project->id])
        ->set('name', 'backend')
        ->set('url', 'https://github.com/datashaman/specify.git')
        ->set('provider', 'github')
        ->set('default_branch', 'main')
        ->call('attach')
        ->assertStatus(403);

    expect($project->fresh()->repos()->count())->toBe(0);
});

test('out-of-scope project 404s', function () {
    ['user' => $user] = repoScene();
    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();

    $this->actingAs($user);

    Livewire::test('pages::projects.repos', ['project' => $otherProject->id])
        ->assertStatus(404);
});

test('shows missing-token and no-webhook badges', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $repo = Repo::factory()->for($ws)->create([
        'access_token' => null,
        'webhook_secret' => null,
    ]);
    $project->attachRepo($repo);

    $this->actingAs($user);

    Livewire::test('pages::projects.repos', ['project' => $project->id])
        ->assertSee('missing token')
        ->assertSee('no webhook');
});
