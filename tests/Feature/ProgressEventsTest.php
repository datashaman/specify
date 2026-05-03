<?php

use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use App\Models\ApprovalPolicy;
use App\Models\Repo;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ExecutionService;
use App\Services\Executors\CliExecutor;
use App\Services\Executors\Executor;
use App\Services\Executors\FakeExecutor;
use App\Services\Executors\LaravelAiExecutor;
use App\Services\Progress\ProgressEmitter;
use App\Services\WorkspaceRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/specify-*') as $path) {
        is_file($path) ? @unlink($path) : File::deleteDirectory($path);
    }
});

test('ProgressEmitter persists rows with monotonic seq scoped to the run', function () {
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->getKey(),
        'status' => AgentRunStatus::Running->value,
    ]);

    $emitter = new ProgressEmitter($run);
    $emitter->emit('stdout', ['line' => 'first']);
    $emitter->emit('stdout', ['line' => 'second']);
    $emitter->setPhase('commit');
    $emitter->emit('stderr', ['line' => 'oops']);

    $events = AgentRunEvent::where('agent_run_id', $run->getKey())->orderBy('seq')->get();

    expect($events)->toHaveCount(3);
    expect($events->pluck('seq')->all())->toBe([1, 2, 3]);
    expect($events->pluck('phase')->all())->toBe(['execute', 'execute', 'commit']);
    expect($events->pluck('type')->all())->toBe(['stdout', 'stdout', 'stderr']);
    expect($events[0]->payload)->toBe(['line' => 'first']);
});

test('ProgressEmitter primes seq from the highest existing row', function () {
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->getKey(),
        'status' => AgentRunStatus::Running->value,
    ]);

    AgentRunEvent::create([
        'agent_run_id' => $run->getKey(),
        'seq' => 7,
        'phase' => 'execute',
        'type' => 'stdout',
        'payload' => ['line' => 'pre-existing'],
        'ts' => now(),
    ]);

    (new ProgressEmitter($run))->emit('stdout', ['line' => 'next']);

    expect(AgentRunEvent::where('agent_run_id', $run->getKey())->max('seq'))->toBe(8);
});

test('AgentRunEvent rows are immutable — update and delete throw', function () {
    $event = AgentRunEvent::factory()->create();

    expect(fn () => $event->update(['type' => 'changed']))
        ->toThrow(RuntimeException::class, 'immutable');

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'immutable');
});

test('CliExecutor emits stdout / stderr / sentinel events when an emitter is passed', function () {
    $bin = sys_get_temp_dir().'/specify-fake-agent-'.uniqid().'.sh';
    File::put($bin, "#!/usr/bin/env bash\nset -e\ncat > /dev/null\necho 'hello'\necho 'oops' >&2\necho '<<<SPECIFY:already_complete>>>abc<<<END>>>'\n");
    chmod($bin, 0o755);

    $workingDir = sys_get_temp_dir().'/specify-cwd-'.uniqid();
    File::ensureDirectoryExists($workingDir);
    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $workingDir)->mustRun();
    new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--allow-empty', '-m', 'init'], $workingDir)->mustRun();

    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create(['name' => 'x'])->getKey(),
        'status' => AgentRunStatus::Running->value,
    ]);

    $emitter = new ProgressEmitter($run);
    $emitter->setPhase('execute');

    (new CliExecutor([$bin]))
        ->execute($run->runnable, $workingDir, null, null, null, $emitter, null);

    $events = AgentRunEvent::where('agent_run_id', $run->getKey())->orderBy('seq')->get();
    $types = $events->pluck('type')->all();

    expect($types)->toContain('stdout', 'stderr', 'sentinel');
    $stdoutLines = $events->where('type', 'stdout')->pluck('payload.line')->all();
    expect($stdoutLines)->toContain('hello');
    expect($events->where('type', 'stderr')->pluck('payload.line')->all())->toContain('oops');
    expect($events->where('type', 'sentinel')->first()->payload)->toBe(['name' => 'already_complete']);
});

test('CliExecutor.supportsProgressEvents()=true; LaravelAi/Fake=false', function () {
    expect((new CliExecutor(['/bin/true']))->supportsProgressEvents())->toBeTrue();
    expect((new LaravelAiExecutor)->supportsProgressEvents())->toBeFalse();
    expect((new FakeExecutor)->supportsProgressEvents())->toBeFalse();
});

test('SubtaskRunPipeline tags events with the current phase and writes them under the run', function () {
    config(['queue.default' => 'sync']);
    config(['specify.executor.default' => 'cli']);
    config(['specify.workspace.open_pr_after_push' => false]);

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

    $bin = sys_get_temp_dir().'/specify-fake-agent-'.uniqid().'.sh';
    File::put($bin, "#!/usr/bin/env bash\nset -e\ncat > /dev/null\necho 'edited' >> README.md\necho 'agent ok'\n");
    chmod($bin, 0o755);
    config(['specify.executor.drivers.cli.command' => [$bin]]);
    config(['specify.runs_path' => sys_get_temp_dir().'/specify-runs-'.uniqid()]);
    app()->forgetInstance(WorkspaceRunner::class);
    app()->forgetInstance(Executor::class);

    $story = makeStory();
    $project = $story->feature->project;
    $repo = Repo::factory()->for($project->team->workspace)->create([
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

    expect($run->status)->toBe(AgentRunStatus::Succeeded);

    $events = AgentRunEvent::where('agent_run_id', $run->getKey())->orderBy('seq')->get();
    expect($events)->not->toBeEmpty();
    $phases = $events->pluck('phase')->unique()->values()->all();
    expect($phases)->toContain('execute');
    expect($events->pluck('seq')->all())->toBe(range(1, $events->count()));
});

test('GET /runs/{run}/events returns events scoped + after a cursor', function () {
    $story = makeStory();
    $project = $story->feature->project;
    $user = User::factory()->create();
    $project->team->addMember($user);

    $task = Task::factory()->for($story)->create(['position' => 0]);
    $subtask = Subtask::factory()->for($task)->create(['position' => 0]);

    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'status' => AgentRunStatus::Running->value,
    ]);

    foreach (range(1, 5) as $i) {
        AgentRunEvent::create([
            'agent_run_id' => $run->getKey(),
            'seq' => $i,
            'phase' => 'execute',
            'type' => 'stdout',
            'payload' => ['line' => "line $i"],
            'ts' => now(),
        ]);
    }

    $body = $this->actingAs($user)->getJson(route('runs.events', ['run' => $run->id]))
        ->assertOk()
        ->json();

    expect($body['cursor'])->toBe(5);
    expect($body['events'])->toHaveCount(5);
    expect($body['events'][0]['payload'])->toBe(['line' => 'line 1']);

    $body = $this->actingAs($user)->getJson(route('runs.events', ['run' => $run->id, 'after' => 3]))
        ->assertOk()
        ->json();

    expect($body['events'])->toHaveCount(2);
    expect(array_column($body['events'], 'seq'))->toBe([4, 5]);
});

test('GET /runs/{run}/events 404s when the user has no project access', function () {
    $outsider = User::factory()->create();
    Workspace::factory()->for($outsider, 'owner')->create();

    $story = makeStory();
    $task = Task::factory()->for($story)->create(['position' => 0]);
    $subtask = Subtask::factory()->for($task)->create(['position' => 0]);
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'status' => AgentRunStatus::Running->value,
    ]);

    $this->actingAs($outsider->fresh())->getJson(route('runs.events', ['run' => $run->id]))
        ->assertNotFound();
});
