<?php

use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\Repo;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ExecutionService;
use App\Services\Executors\CliExecutor;
use App\Services\Executors\Executor;
use App\Services\WorkspaceRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function fakeAgentBinary(string $body): string
{
    $path = sys_get_temp_dir().'/specify-fake-agent-'.uniqid().'.sh';
    File::put($path, "#!/usr/bin/env bash\nset -e\n".$body."\n");
    chmod($path, 0o755);

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/specify-*') as $path) {
        is_file($path) ? @unlink($path) : File::deleteDirectory($path);
    }
});

test('CliExecutor runs the configured binary in cwd, captures stdout, observes git changes', function () {
    $bin = fakeAgentBinary(<<<'BASH'
        cat > prompt-received.txt
        echo "added file from agent" > new-from-agent.txt
        echo "agent ran successfully"
    BASH);

    $workingDir = sys_get_temp_dir().'/specify-cwd-'.uniqid();
    File::ensureDirectoryExists($workingDir);

    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $workingDir)->mustRun();
    new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--allow-empty', '-m', 'init'], $workingDir)->mustRun();

    $subtask = Subtask::factory()->create(['name' => 'add export', 'description' => 'export users']);

    $executor = new CliExecutor([$bin]);
    $output = $executor->execute($subtask, $workingDir, repo: null, workingBranch: 'specify/test');

    expect($output->summary)->toContain('agent ran successfully')
        ->and($output->filesChanged)->toContain('new-from-agent.txt')
        ->and($output->filesChanged)->toContain('prompt-received.txt')
        ->and($output->commitMessage)->toBe('feat: add export')
        ->and(File::get($workingDir.'/prompt-received.txt'))->toContain('add export');
});

test('CliExecutor refuses to run when no working directory is provided', function () {
    $subtask = Subtask::factory()->create();
    $executor = new CliExecutor(['/bin/true']);

    expect(fn () => $executor->execute($subtask, null, null, null))
        ->toThrow(RuntimeException::class, 'working directory');
});

test('CliExecutor records the full transcript on executor_log including stderr', function () {
    $bin = fakeAgentBinary(<<<'BASH'
        cat > /dev/null
        echo "step 1: read files"
        echo "step 2: edit files"
        echo "warning: skipping mock" >&2
        echo "done"
    BASH);

    $workingDir = sys_get_temp_dir().'/specify-cwd-'.uniqid();
    File::ensureDirectoryExists($workingDir);
    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $workingDir)->mustRun();
    new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--allow-empty', '-m', 'init'], $workingDir)->mustRun();

    $subtask = Subtask::factory()->create(['name' => 'log test']);
    $output = (new CliExecutor([$bin]))->execute($subtask, $workingDir, repo: null, workingBranch: null);

    expect($output->executorLog)
        ->toContain('step 1: read files')
        ->toContain('step 2: edit files')
        ->toContain('done')
        ->toContain('--- stderr ---')
        ->toContain('warning: skipping mock');

    expect($output->toArray())->toHaveKey('executor_log');
});

test('CliExecutor clamps the summary to the trailing chunk to keep PR bodies bounded', function () {
    $bin = fakeAgentBinary(<<<'BASH'
        cat > /dev/null
        for i in $(seq 1 1000); do echo "verbose line $i with extra padding to push past the limit"; done
        echo "FINAL: agent ran successfully"
    BASH);

    $workingDir = sys_get_temp_dir().'/specify-cwd-'.uniqid();
    File::ensureDirectoryExists($workingDir);
    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $workingDir)->mustRun();
    new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--allow-empty', '-m', 'init'], $workingDir)->mustRun();

    $subtask = Subtask::factory()->create(['name' => 'verbose run']);
    $output = (new CliExecutor([$bin]))->execute($subtask, $workingDir, repo: null, workingBranch: null);

    expect(strlen($output->summary))->toBeLessThanOrEqual(4_096)
        ->and($output->summary)->toContain('FINAL: agent ran successfully');

    expect(strlen((string) $output->executorLog))->toBeGreaterThan(strlen($output->summary));
});

test('CliExecutor truncates executor_log at 64 KB', function () {
    $bin = fakeAgentBinary(<<<'BASH'
        cat > /dev/null
        for i in $(seq 1 50000); do echo "line $i ----------------------------------------"; done
        echo "tail"
    BASH);

    $workingDir = sys_get_temp_dir().'/specify-cwd-'.uniqid();
    File::ensureDirectoryExists($workingDir);
    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $workingDir)->mustRun();
    new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--allow-empty', '-m', 'init'], $workingDir)->mustRun();

    $subtask = Subtask::factory()->create(['name' => 'huge run']);
    $output = (new CliExecutor([$bin]))->execute($subtask, $workingDir, repo: null, workingBranch: null);

    expect(strlen((string) $output->executorLog))->toBeLessThanOrEqual(65_536 + 32)
        ->and($output->executorLog)->toContain('[truncated]');
});

test('CliExecutor surfaces non-zero exit codes as exceptions', function () {
    $bin = fakeAgentBinary(<<<'BASH'
        echo "boom" >&2
        exit 7
    BASH);

    $workingDir = sys_get_temp_dir().'/specify-cwd-'.uniqid();
    File::ensureDirectoryExists($workingDir);
    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $workingDir)->mustRun();

    $subtask = Subtask::factory()->create(['name' => 'x']);
    $executor = new CliExecutor([$bin]);

    expect(fn () => $executor->execute($subtask, $workingDir, null, null))
        ->toThrow(RuntimeException::class, 'CLI executor failed');
});

test('full pipeline with cli driver: clone → branch → cli edits → commit → diff → subtask Done', function () {
    config(['queue.default' => 'sync']);
    config(['specify.executor.driver' => 'cli']);
    config(['specify.workspace.open_pr_after_push' => false]);

    // Build a source bare repo seeded with one commit on main.
    $bare = sys_get_temp_dir().'/specify-src-'.uniqid().'.git';
    new Process(['git', 'init', '--bare', '--initial-branch=main', $bare])->mustRun();
    $seed = sys_get_temp_dir().'/specify-seed-'.uniqid();
    File::ensureDirectoryExists($seed);
    new Process(['git', 'init', '--initial-branch=main', $seed])->mustRun();
    File::put($seed.'/README.md', "# hi\n");
    new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'add', 'README.md'], $seed)->mustRun();
    new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-m', 'initial'], $seed)->mustRun();
    new Process(['git', 'remote', 'add', 'origin', 'file://'.$bare], $seed)->mustRun();
    new Process(['git', 'push', 'origin', 'main'], $seed)->mustRun();
    File::deleteDirectory($seed);

    $bin = fakeAgentBinary(<<<'BASH'
        cat > .agent-prompt.txt
        echo "edited by agent" >> README.md
        echo "new" > AGENT_NOTE.md
        echo "ok"
    BASH);
    config(['specify.executor.cli.command' => [$bin]]);

    config(['specify.runs_path' => sys_get_temp_dir().'/specify-runs-'.uniqid()]);
    app()->forgetInstance(WorkspaceRunner::class);
    app()->forgetInstance(Executor::class);

    $story = makeStory();
    $project = $story->feature->project;
    $workspace = $project->team->workspace;
    $repo = Repo::factory()->for($workspace)->create([
        'url' => 'file://'.$bare,
        'default_branch' => 'main',
    ]);
    $project->attachRepo($repo);

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 0,
    ]);

    $ac = $story->acceptanceCriteria()->first() ?? AcceptanceCriterion::factory()->for($story)->create();
    $task = Task::factory()->for($story)->create(['name' => 'append', 'position' => 0, 'acceptance_criterion_id' => $ac->id]);
    $subtask = Subtask::factory()->for($task)->create(['name' => 'append', 'position' => 0]);
    $story->forceFill(['status' => StoryStatus::Draft->value])->save();
    $story->fresh()->submitForApproval();
    app(ExecutionService::class)->startStoryExecution($story->fresh());

    $run = AgentRun::where('runnable_id', $subtask->id)
        ->where('runnable_type', Subtask::class)
        ->latest('id')->firstOrFail();

    expect($run->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->output['commit_sha'] ?? null)->toBeString()
        ->and($run->output['pushed'] ?? null)->toBeTrue()
        ->and($run->diff)->toContain('AGENT_NOTE.md')
        ->and($run->diff)->toContain('edited by agent')
        ->and($subtask->fresh()->status)->toBe(TaskStatus::Done)
        ->and($story->fresh()->status)->toBe(StoryStatus::Done);

    $remoteBranches = new Process(['git', 'branch', '--list'], $bare);
    $remoteBranches->mustRun();
    expect($remoteBranches->getOutput())->toContain($run->working_branch);
});
