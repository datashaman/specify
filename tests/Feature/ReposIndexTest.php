<?php

use App\Enums\RepoProvider;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function repoScene(TeamRole $role = TeamRole::Admin, bool $pin = true): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create(['github_token' => 'gho_user_token']);
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);
    $user->forceFill([
        'current_team_id' => $team->id,
        'current_project_id' => $pin ? $project->id : null,
    ])->save();

    return compact('user', 'project', 'workspace');
}

afterEach(function () {
    Cache::flush();
});

test('lists project repos with a no-webhook badge by default', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $repo = Repo::factory()->for($ws)->create([
        'name' => 'backend',
        'webhook_secret' => null,
    ]);
    $project->attachRepo($repo);
    $this->actingAs($user);

    Livewire::test('pages::repos.index', ['project' => $project->id])
        ->assertSee('backend')
        ->assertSee('no webhook');
});

test('admin can mark a repo primary; setPrimary flips the pivot flag', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $a = Repo::factory()->for($ws)->create();
    $b = Repo::factory()->for($ws)->create();
    $project->attachRepo($a, primary: true);
    $project->attachRepo($b);
    $this->actingAs($user);

    Livewire::test('pages::repos.index', ['project' => $project->id])
        ->call('setPrimary', $b->id)
        ->assertHasNoErrors();

    expect((bool) $project->repos()->find($b->id)->pivot->is_primary)->toBeTrue()
        ->and((bool) $project->repos()->find($a->id)->pivot->is_primary)->toBeFalse();
});

test('member cannot setPrimary', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene(TeamRole::Member);
    $repo = Repo::factory()->for($ws)->create();
    $project->attachRepo($repo);
    $this->actingAs($user);

    Livewire::test('pages::repos.index', ['project' => $project->id])
        ->call('setPrimary', $repo->id)
        ->assertStatus(403);
});

test('remove detaches the project pivot AND deletes the workspace repo', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Generic,
        'webhook_secret' => null,
    ]);
    $project->attachRepo($repo);
    $this->actingAs($user);

    Livewire::test('pages::repos.index', ['project' => $project->id])
        ->call('remove', $repo->id)
        ->assertHasNoErrors();

    expect($project->fresh()->repos()->count())->toBe(0)
        ->and(Repo::where('id', $repo->id)->exists())->toBeFalse();
});

test('member cannot remove a repo', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene(TeamRole::Member);
    $repo = Repo::factory()->for($ws)->create();
    $project->attachRepo($repo);
    $this->actingAs($user);

    Livewire::test('pages::repos.index', ['project' => $project->id])
        ->call('remove', $repo->id)
        ->assertStatus(403);

    expect(Repo::where('id', $repo->id)->exists())->toBeTrue();
});

test('addGithubRepo creates a workspace repo and attaches it to the project', function () {
    ['user' => $user, 'project' => $project, 'workspace' => $ws] = repoScene();
    $this->actingAs($user);

    Http::fake([
        'api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'datashaman/specify',
                'name' => 'specify',
                'html_url' => 'https://github.com/datashaman/specify',
                'default_branch' => 'main',
                'private' => false,
            ],
        ], 200),
        'api.github.com/repos/datashaman/specify/hooks*' => Http::response(['id' => 1], 201),
    ]);

    Livewire::test('pages::repos.index', ['project' => $project->id])
        ->call('addGithubRepo', 'datashaman/specify')
        ->assertHasNoErrors();

    $repo = Repo::where('workspace_id', $ws->id)->first();
    expect($repo)->not->toBeNull()
        ->and($repo->url)->toBe('https://github.com/datashaman/specify.git')
        ->and($project->fresh()->repos()->pluck('id')->all())->toContain($repo->id);
});

test('member cannot addGithubRepo', function () {
    ['user' => $user, 'project' => $project] = repoScene(TeamRole::Member);
    $this->actingAs($user);

    Livewire::test('pages::repos.index', ['project' => $project->id])
        ->call('addGithubRepo', 'datashaman/specify')
        ->assertStatus(403);

    expect(Repo::count())->toBe(0);
});
