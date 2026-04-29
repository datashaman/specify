<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function repoScene(TeamRole $role = TeamRole::Admin, bool $pin = true): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);
    $user->forceFill([
        'current_team_id' => $team->id,
        'current_project_id' => $pin ? $project->id : null,
    ])->save();

    return compact('user', 'project', 'workspace');
}

test('admin saves a workspace repo without a pinned project', function () {
    ['user' => $user, 'workspace' => $ws] = repoScene(pin: false);
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->set('name', 'backend')
        ->set('url', 'https://github.com/datashaman/specify.git')
        ->set('access_token', 'ghp_xxx')
        ->call('save')
        ->assertHasNoErrors();

    $repo = Repo::where('workspace_id', $ws->id)->first();
    expect($repo)->not->toBeNull()
        ->and($repo->access_token)->toBe('ghp_xxx');
});

test('with project pinned, attach links the existing workspace repo to the project', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $repo = Repo::factory()->for($ws)->create(['name' => 'backend']);
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->call('attach', $repo->id);

    expect($project->fresh()->repos()->pluck('id')->all())->toContain($repo->id);
});

test('detach removes the project pivot but keeps the workspace repo', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $repo = Repo::factory()->for($ws)->create();
    $project->attachRepo($repo);
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->call('detach', $repo->id);

    expect($project->fresh()->repos()->count())->toBe(0)
        ->and(Repo::where('id', $repo->id)->exists())->toBeTrue();
});

test('delete refuses while the repo is attached to any project', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene(pin: false);
    $repo = Repo::factory()->for($ws)->create();
    $project->attachRepo($repo);
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->call('delete', $repo->id)
        ->assertHasErrors(['repos']);

    expect(Repo::where('id', $repo->id)->exists())->toBeTrue();
});

test('delete removes the repo when no project is attached', function () {
    ['user' => $user, 'workspace' => $ws] = repoScene(pin: false);
    $repo = Repo::factory()->for($ws)->create();
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->call('delete', $repo->id)
        ->assertHasNoErrors();

    expect(Repo::where('id', $repo->id)->exists())->toBeFalse();
});

test('edit prefills the form, save updates the row, token left blank is preserved', function () {
    ['user' => $user, 'workspace' => $ws] = repoScene(pin: false);
    $repo = Repo::factory()->for($ws)->create([
        'name' => 'old',
        'url' => 'https://github.com/datashaman/specify.git',
        'access_token' => 'kept_token',
    ]);
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->call('edit', $repo->id)
        ->assertSet('name', 'old')
        ->set('name', 'new')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $repo->fresh();
    expect($fresh->name)->toBe('new')
        ->and($fresh->access_token)->toBe('kept_token');
});

test('member cannot save a repo', function () {
    ['user' => $user] = repoScene(TeamRole::Member, pin: false);
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->set('name', 'backend')
        ->set('url', 'https://github.com/datashaman/specify.git')
        ->call('save')
        ->assertStatus(403);

    expect(Repo::count())->toBe(0);
});

test('shows missing-token and no-webhook badges', function () {
    ['user' => $user, 'workspace' => $ws] = repoScene(pin: false);
    Repo::factory()->for($ws)->create([
        'access_token' => null,
        'webhook_secret' => null,
    ]);
    $this->actingAs($user);

    Livewire::test('pages::repos.index')
        ->assertSee('missing token')
        ->assertSee('no webhook');
});
