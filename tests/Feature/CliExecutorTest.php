<?php

use App\Enums\AgentRunStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\Plan;
use App\Models\Repo;
use App\Models\Task;
use App\Services\ExecutionService;
use App\Services\Executors\CliExecutor;
use App\Services\Executors\Executor;
use App\Services\WorkspaceRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Build a fake "agent CLI" — a tiny shell script that:
 *   - writes the prompt it received (stdin) to a sibling file in cwd,
 *   - creates/edits a file inside cwd (so git status reports a change),
 *   - prints a one-line summary on stdout.
 *
 * Returns the absolute path to the script.
 */
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

    $task = Task::factory()->create(['name' => 'add export', 'description' => 'export users']);

    $executor = new CliExecutor([$bin]);
    $output = $executor->execute($task, $workingDir, repo: null, workingBranch: 'specify/test');

    expect($output['summary'])->toContain('agent ran successfully')
        ->and($output['files_changed'])->toContain('new-from-agent.txt')
        ->and($output['files_changed'])->toContain('prompt-received.txt')
        ->and($output['commit_message'])->toBe('feat: add export')
        ->and(File::get($workingDir.'/prompt-received.txt'))->toContain('add export');
});

test('CliExecutor refuses to run when no working directory is provided', function () {
    $task = Task::factory()->create();
    $executor = new CliExecutor(['/bin/true']);

    expect(fn () => $executor->execute($task, null, null, null))
        ->toThrow(RuntimeException::class, 'working directory');
});

test('CliExecutor surfaces non-zero exit codes as exceptions', function () {
    $bin = fakeAgentBinary(<<<'BASH'
        echo "boom" >&2
        exit 7
    BASH);

    $workingDir = sys_get_temp_dir().'/specify-cwd-'.uniqid();
    File::ensureDirectoryExists($workingDir);
    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $workingDir)->mustRun();

    $task = Task::factory()->create(['name' => 'x']);
    $executor = new CliExecutor([$bin]);

    expect(fn () => $executor->execute($task, $workingDir, null, null))
        ->toThrow(RuntimeException::class, 'CLI executor failed');
});

test('full pipeline with cli driver: clone → branch → cli edits → commit → diff → task Done', function () {
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

    // Configure the fake agent — appends a line to README and a new file.
    $bin = fakeAgentBinary(<<<'BASH'
        cat > .agent-prompt.txt
        echo "edited by agent" >> README.md
        echo "new" > AGENT_NOTE.md
        echo "ok"
    BASH);
    config(['specify.executor.cli.command' => [$bin]]);

    // Use a temp runs path so we don't litter storage/.
    config(['specify.runs_path' => sys_get_temp_dir().'/specify-runs-'.uniqid()]);
    app()->forgetInstance(WorkspaceRunner::class);
    app()->forgetInstance(Executor::class);

    // Build a story → project → workspace → repo (pointing to our bare).
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

    $plan = Plan::factory()->for($story)->create();
    $task = Task::factory()->for($plan)->create(['name' => 'append', 'position' => 0]);
    $plan->submitForApproval();

    $run = AgentRun::where('runnable_id', $task->id)->latest('id')->firstOrFail();

    expect($run->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->output['commit_sha'] ?? null)->toBeString()
        ->and($run->output['pushed'] ?? null)->toBeTrue()
        ->and($run->diff)->toContain('AGENT_NOTE.md')
        ->and($run->diff)->toContain('edited by agent')
        ->and($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($plan->fresh()->status)->toBe(PlanStatus::Done);

    $remoteBranches = new Process(['git', 'branch', '--list'], $bare);
    $remoteBranches->mustRun();
    expect($remoteBranches->getOutput())->toContain($run->working_branch);
});
