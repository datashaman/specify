<?php

use App\Ai\Agents\SubtaskExecutor;
use App\Enums\AgentRunStatus;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteSubtaskJob;
use App\Models\AgentRun;
use App\Models\Subtask;
use App\Models\User;
use App\Services\ExecutionService;
use App\Services\Executors\ExecutorFactory;
use App\Services\SubtaskRunPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
});

test('cancelRun on a Queued run transitions to Cancelled and records the audit flag', function () {
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->id,
        'status' => AgentRunStatus::Queued,
    ]);

    app(ExecutionService::class)->cancelRun($run);

    expect($run->fresh()->status)->toBe(AgentRunStatus::Cancelled);
    // ADR-0010: the flag stays set after the terminal transition so a
    // Cancelled run is distinguishable from one that terminated via some
    // other path. (The Queued path used to leave the flag false; that was
    // a divergence from the ADR text.)
    expect($run->fresh()->cancel_requested)->toBeTrue();
});

test('cancelRun on a Running run sets cancel_requested but stays Running', function () {
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->id,
        'status' => AgentRunStatus::Running,
    ]);

    app(ExecutionService::class)->cancelRun($run);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(AgentRunStatus::Running);
    expect($fresh->cancel_requested)->toBeTrue();
});

test('cancelRun on a terminal run is a no-op', function () {
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->id,
        'status' => AgentRunStatus::Succeeded,
    ]);

    $changed = app(ExecutionService::class)->cancelRun($run);

    expect($changed)->toBeFalse();
    expect($run->fresh()->status)->toBe(AgentRunStatus::Succeeded);
    expect($run->fresh()->cancel_requested)->toBeFalse();
});

test('SubtaskRunPipeline observes cancel_requested mid-execute and marks the run Cancelled', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();

    SubtaskExecutor::fake(function () use ($subtask) {
        AgentRun::query()
            ->where('runnable_type', Subtask::class)
            ->where('runnable_id', $subtask->id)
            ->update(['cancel_requested' => true]);

        return [
            'summary' => 'agent finished but cancel was requested',
            'files_changed' => [],
            'commit_message' => 'noop',
        ];
    });

    app(ExecutionService::class)->startStoryExecution($story);

    $run = AgentRun::query()
        ->where('runnable_type', Subtask::class)
        ->where('runnable_id', $subtask->id)
        ->latest('id')
        ->first();

    expect($run->status)->toBe(AgentRunStatus::Cancelled);
    expect($run->error_message)->toContain('Cancelled');
    expect($subtask->fresh()->status)->toBe(TaskStatus::Blocked);
});

test('cancelSubtask cancels every still-open AgentRun on the Subtask', function () {
    $subtask = Subtask::factory()->create();

    $queued = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Queued,
    ]);
    $running = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Running,
    ]);
    $done = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
    ]);

    $changed = app(ExecutionService::class)->cancelSubtask($subtask);

    expect($changed)->toBe(2);
    expect($queued->fresh()->status)->toBe(AgentRunStatus::Cancelled);
    expect($queued->fresh()->cancel_requested)->toBeTrue();
    expect($running->fresh()->cancel_requested)->toBeTrue();
    expect($done->fresh()->status)->toBe(AgentRunStatus::Succeeded);
});

test('Run console exposes a Cancel button while the run is open', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Running,
    ]);

    $project = $story->feature->project;
    $member = User::factory()->create();
    $project->team->addMember($member);

    $this->actingAs($member)
        ->get("/projects/{$project->id}/stories/{$story->id}/subtasks/{$subtask->id}/runs/{$run->id}")
        ->assertOk()
        ->assertSee('Cancel run');
});

test('Run console hides the Cancel button on terminal runs', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
    ]);

    $project = $story->feature->project;
    $member = User::factory()->create();
    $project->team->addMember($member);

    $this->actingAs($member)
        ->get("/projects/{$project->id}/stories/{$story->id}/subtasks/{$subtask->id}/runs/{$run->id}")
        ->assertOk()
        ->assertDontSee('Cancel run');
});

test('Queued run cancelled before the worker picks up the job stays Cancelled', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();

    // Construct a Queued run as if dispatch happened on a queued worker that
    // hasn't picked it up. Cancel before invoking the job manually.
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $story->feature->project->primaryRepo()->id,
        'working_branch' => 'specify/'.$story->feature->slug.'/'.$story->slug,
        'executor_driver' => 'fake',
        'status' => AgentRunStatus::Queued,
    ]);

    app(ExecutionService::class)->cancelRun($run);
    expect($run->fresh()->status)->toBe(AgentRunStatus::Cancelled);

    SubtaskExecutor::fake(fn () => [
        'summary' => 'should not run',
        'files_changed' => ['app/A.php'],
        'commit_message' => 'should not commit',
    ]);
    (new ExecuteSubtaskJob($run->id))->handle(
        app(ExecutionService::class),
        app(SubtaskRunPipeline::class),
        app(ExecutorFactory::class),
    );

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(AgentRunStatus::Cancelled);
    expect($fresh->started_at)->toBeNull();
    expect($fresh->output['pull_request_url'] ?? null)->toBeNull();
});

test('markRunning loses the queued-cancel race when the row is Cancelled mid-handle', function () {
    // Simulates: ExecuteSubtaskJob::handle() loads the AgentRun (still
    // Queued at that snapshot), then cancelRun runs in another transaction
    // and flips the row to Cancelled before markRunning fires. The
    // conditional UPDATE must match zero rows so we don't overwrite the
    // terminal state — closes the queued-cancel race (ADR-0010).
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->id,
        'status' => AgentRunStatus::Queued,
    ]);

    AgentRun::query()->whereKey($run->getKey())->update([
        'status' => AgentRunStatus::Cancelled->value,
        'cancel_requested' => true,
    ]);

    $went = app(ExecutionService::class)->markRunning($run);

    expect($went)->toBeFalse();
    expect($run->fresh()->status)->toBe(AgentRunStatus::Cancelled);
});

test('AgentRunStatus::Cancelled is a failure-class terminal state', function () {
    expect(AgentRunStatus::Cancelled->isTerminal())->toBeTrue();
    expect(AgentRunStatus::Cancelled->isFailure())->toBeTrue();
});
