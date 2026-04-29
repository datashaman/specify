<?php

use App\Enums\AgentRunStatus;
use App\Enums\RepoProvider;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Task;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function projectInWorkspace(Workspace $ws): Project
{
    $team = Team::factory()->for($ws)->create();

    return Project::factory()->for($team)->create();
}

test('workspace has many repos', function () {
    $ws = Workspace::factory()->create();
    Repo::factory()->for($ws)->count(3)->create();

    expect($ws->repos)->toHaveCount(3);
});

test('repo url is unique within a workspace, free across workspaces', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    Repo::factory()->for($a)->create(['url' => 'https://github.com/x/foo.git']);
    Repo::factory()->for($b)->create(['url' => 'https://github.com/x/foo.git']);

    expect(fn () => Repo::factory()->for($a)->create(['url' => 'https://github.com/x/foo.git']))
        ->toThrow(Exception::class);
});

test('first repo attached becomes primary automatically', function () {
    $ws = Workspace::factory()->create();
    $project = projectInWorkspace($ws);
    $repo = Repo::factory()->for($ws)->create();

    $project->attachRepo($repo);

    expect($project->primaryRepo()?->id)->toBe($repo->id);
});

test('attaching a second repo with primary=true swaps the primary', function () {
    $ws = Workspace::factory()->create();
    $project = projectInWorkspace($ws);
    $repoA = Repo::factory()->for($ws)->create();
    $repoB = Repo::factory()->for($ws)->create();

    $project->attachRepo($repoA);
    $project->attachRepo($repoB, primary: true);

    expect($project->primaryRepo()?->id)->toBe($repoB->id)
        ->and($project->repos()->wherePivot('is_primary', true)->count())->toBe(1);
});

test('setPrimaryRepo swaps primary flag', function () {
    $ws = Workspace::factory()->create();
    $project = projectInWorkspace($ws);
    $repoA = Repo::factory()->for($ws)->create();
    $repoB = Repo::factory()->for($ws)->create();

    $project->attachRepo($repoA);
    $project->attachRepo($repoB);
    $project->setPrimaryRepo($repoB);

    expect($project->primaryRepo()?->id)->toBe($repoB->id)
        ->and($project->repos()->wherePivot('is_primary', true)->count())->toBe(1);
});

test('attachRepo rejects a repo from a different workspace', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    $project = projectInWorkspace($a);
    $repo = Repo::factory()->for($b)->create();

    expect(fn () => $project->attachRepo($repo))
        ->toThrow(InvalidArgumentException::class, 'same workspace');
});

test('multi-repo project: three repos with role labels and one primary', function () {
    $ws = Workspace::factory()->create();
    $project = projectInWorkspace($ws);
    $backend = Repo::factory()->for($ws)->create(['name' => 'backend']);
    $server = Repo::factory()->for($ws)->create(['name' => 'server']);
    $worker = Repo::factory()->for($ws)->create(['name' => 'worker']);

    $project->attachRepo($backend, role: 'backend', primary: true);
    $project->attachRepo($server, role: 'server');
    $project->attachRepo($worker, role: 'worker');

    expect($project->repos)->toHaveCount(3)
        ->and($project->primaryRepo()?->id)->toBe($backend->id)
        ->and($project->repos->where('id', $server->id)->first()->pivot->role)->toBe('server')
        ->and($project->repos->where('id', $worker->id)->first()->pivot->role)->toBe('worker');
});

test('two projects in same workspace can share a repo', function () {
    $ws = Workspace::factory()->create();
    $p1 = projectInWorkspace($ws);
    $p2 = projectInWorkspace($ws);
    $repo = Repo::factory()->for($ws)->create();

    $p1->attachRepo($repo);
    $p2->attachRepo($repo);

    expect($repo->projects)->toHaveCount(2);
});

test('access_token round-trips and is encrypted at rest', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create(['access_token' => 'ghp_secret_value_123']);

    expect($repo->fresh()->access_token)->toBe('ghp_secret_value_123');

    $raw = DB::table('repos')->where('id', $repo->id)->value('access_token');
    expect($raw)->not->toBe('ghp_secret_value_123')
        ->and(strlen($raw))->toBeGreaterThan(20);
});

test('AgentRun belongs to a Repo and remembers working_branch', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create();
    $task = Task::factory()->create();

    $run = AgentRun::create([
        'runnable_type' => $task->getMorphClass(),
        'runnable_id' => $task->getKey(),
        'repo_id' => $repo->id,
        'working_branch' => 'feat/specify-1',
        'status' => AgentRunStatus::Queued,
    ]);

    expect($run->fresh()->repo->id)->toBe($repo->id)
        ->and($run->fresh()->working_branch)->toBe('feat/specify-1');
});

test('provider enum cast round-trips', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create(['provider' => RepoProvider::Gitlab]);

    expect($repo->fresh()->provider)->toBe(RepoProvider::Gitlab);
});

test('deleting workspace cascades to repos and pivot rows', function () {
    $ws = Workspace::factory()->create();
    $project = projectInWorkspace($ws);
    $repo = Repo::factory()->for($ws)->create();
    $project->attachRepo($repo);

    $ws->delete();

    expect(Repo::find($repo->id))->toBeNull()
        ->and(DB::table('project_repo')->count())->toBe(0);
});
