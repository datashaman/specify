<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\WorkspaceRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function gitInit(string $dir, bool $bare = false): void
{
    $cmd = $bare ? ['git', 'init', '--bare', '--initial-branch=main', $dir] : ['git', 'init', '--initial-branch=main', $dir];
    $p = new Process($cmd);
    $p->mustRun();
}

function gitRun(string $cwd, array $cmd): string
{
    $p = new Process($cmd, $cwd);
    $p->mustRun();

    return $p->getOutput();
}

function makeSourceRepoWithCommit(): string
{
    $bare = sys_get_temp_dir().'/specify-src-'.uniqid().'.git';
    gitInit($bare, bare: true);

    $seed = sys_get_temp_dir().'/specify-seed-'.uniqid();
    File::ensureDirectoryExists($seed);
    gitInit($seed);
    File::put($seed.'/README.md', "# Hello\n");
    gitRun($seed, ['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'add', 'README.md']);
    gitRun($seed, ['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-m', 'initial']);
    gitRun($seed, ['git', 'remote', 'add', 'origin', 'file://'.$bare]);
    gitRun($seed, ['git', 'push', 'origin', 'main']);

    File::deleteDirectory($seed);

    return 'file://'.$bare;
}

function makeRunner(): WorkspaceRunner
{
    $base = sys_get_temp_dir().'/specify-runs-'.uniqid();

    return new WorkspaceRunner($base, 'Specify Bot', 'bot@specify.local');
}

function makeRepoWithUrl(string $url): Repo
{
    $ws = Workspace::factory()->create();

    return Repo::factory()->for($ws)->create(['url' => $url]);
}

function makeAgentRun(): AgentRun
{
    $task = Task::factory()->create();

    return AgentRun::create([
        'runnable_type' => $task->getMorphClass(),
        'runnable_id' => $task->getKey(),
        'status' => AgentRunStatus::Queued->value,
    ]);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/specify-*') as $path) {
        File::deleteDirectory($path);
    }
});

test('prepare clones a repo into the per-run working directory', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $repo = makeRepoWithUrl($url);
    $run = makeAgentRun();

    $dir = $runner->prepare($repo, $run);

    expect($dir)->toBe($runner->workingDirFor($run))
        ->and(is_dir($dir.'/.git'))->toBeTrue()
        ->and(file_exists($dir.'/README.md'))->toBeTrue();
});

test('prepare on existing dir fetches instead of cloning', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $repo = makeRepoWithUrl($url);
    $run = makeAgentRun();

    $dir = $runner->prepare($repo, $run);
    File::put($dir.'/extra.txt', 'local-only');

    $dir2 = $runner->prepare($repo, $run);

    expect($dir2)->toBe($dir)
        ->and(file_exists($dir.'/extra.txt'))->toBeTrue();
});

test('checkoutBranch creates and switches to a new branch', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $dir = $runner->prepare(makeRepoWithUrl($url), makeAgentRun());

    $runner->checkoutBranch($dir, 'specify/feature', baseBranch: 'main');

    $current = trim(gitRun($dir, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']));
    expect($current)->toBe('specify/feature');
});

test('checkoutBranch resyncs to origin/{branch} when remote has moved ahead', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $repo = makeRepoWithUrl($url);

    // First run pushes a commit on the feature branch.
    $first = $runner->prepare($repo, makeAgentRun());
    $runner->checkoutBranch($first, 'specify/sync-me', baseBranch: 'main');
    File::put($first.'/from-first.txt', "first\n");
    $runner->commit($first, 'feat: first run');
    $runner->push($first, 'specify/sync-me');

    // Second run starts in its own working dir; the branch is already on
    // origin. checkoutBranch must hard-reset local to origin/{branch} so
    // the agent sees the work the first run pushed (the bug it patches).
    $second = $runner->prepare($repo, makeAgentRun());
    $runner->checkoutBranch($second, 'specify/sync-me', baseBranch: 'main');

    expect(file_exists($second.'/from-first.txt'))->toBeTrue();
    $current = trim(gitRun($second, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']));
    expect($current)->toBe('specify/sync-me');
});

test('commit returns null when working tree is clean', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $dir = $runner->prepare(makeRepoWithUrl($url), makeAgentRun());

    expect($runner->commit($dir, 'noop'))->toBeNull();
});

test('commit returns SHA after staging file changes', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $dir = $runner->prepare(makeRepoWithUrl($url), makeAgentRun());
    $runner->checkoutBranch($dir, 'specify/work', baseBranch: 'main');

    File::put($dir.'/new.txt', "added\n");

    $sha = $runner->commit($dir, 'feat: add new');

    expect($sha)->toBeString()->toHaveLength(40);

    $log = gitRun($dir, ['git', 'log', '-1', '--pretty=%s']);
    expect(trim($log))->toBe('feat: add new');
});

test('diff returns the change made in the latest commit', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $dir = $runner->prepare(makeRepoWithUrl($url), makeAgentRun());
    $runner->checkoutBranch($dir, 'specify/work', baseBranch: 'main');

    File::put($dir.'/new.txt', "added\n");
    $runner->commit($dir, 'feat: add new');

    $diff = $runner->diff($dir);

    expect($diff)->toContain('new.txt')
        ->and($diff)->toContain('+added');
});

test('push uploads the working branch to origin', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $dir = $runner->prepare(makeRepoWithUrl($url), makeAgentRun());
    $runner->checkoutBranch($dir, 'specify/push-me', baseBranch: 'main');

    File::put($dir.'/p.txt', "x\n");
    $runner->commit($dir, 'feat: pushable');

    $runner->push($dir, 'specify/push-me');

    $bare = substr($url, strlen('file://'));
    $remoteBranches = gitRun($bare, ['git', 'branch', '--list']);
    expect($remoteBranches)->toContain('specify/push-me');
});

test('cleanup removes the working directory', function () {
    $url = makeSourceRepoWithCommit();
    $runner = makeRunner();
    $run = makeAgentRun();
    $dir = $runner->prepare(makeRepoWithUrl($url), $run);

    expect(is_dir($dir))->toBeTrue();
    $runner->cleanup($dir);
    expect(is_dir($dir))->toBeFalse();
});

test('https URLs are rewritten to inject the access token', function () {
    $reflection = new ReflectionMethod(WorkspaceRunner::class, 'authenticatedUrl');
    $reflection->setAccessible(true);

    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'url' => 'https://github.com/example/foo.git',
        'access_token' => 'ghp_secret_xyz',
    ]);

    $runner = makeRunner();
    $rewritten = $reflection->invoke($runner, $repo);

    expect($rewritten)->toContain('x-access-token:ghp_secret_xyz')
        ->and($rewritten)->toContain('@github.com/example/foo.git');
});

test('non-https URLs (file://, ssh) are left untouched', function () {
    $reflection = new ReflectionMethod(WorkspaceRunner::class, 'authenticatedUrl');
    $reflection->setAccessible(true);

    $ws = Workspace::factory()->create();
    $repoFile = Repo::factory()->for($ws)->create([
        'url' => 'file:///tmp/x.git',
        'access_token' => 'ghp_secret',
    ]);
    $repoSsh = Repo::factory()->for($ws)->create([
        'url' => 'git@github.com:example/foo.git',
        'access_token' => 'ghp_secret',
    ]);

    $runner = makeRunner();
    expect($reflection->invoke($runner, $repoFile))->toBe('file:///tmp/x.git')
        ->and($reflection->invoke($runner, $repoSsh))->toBe('git@github.com:example/foo.git');
});
