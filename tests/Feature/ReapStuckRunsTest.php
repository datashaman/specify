<?php

use App\Enums\AgentRunStatus;
use App\Jobs\ExecuteSubtaskJob;
use App\Jobs\GenerateTasksJob;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('markFailed is idempotent — terminal status is preserved', function () {
    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Succeeded->value,
        'finished_at' => now(),
    ]);

    app(ExecutionService::class)->markFailed($run, 'late callback');

    expect($run->fresh()->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->fresh()->error_message)->toBeNull();
});

test('markFailed flips an active run to Failed', function () {
    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Running->value,
        'started_at' => now()->subMinutes(5),
    ]);

    app(ExecutionService::class)->markFailed($run, 'boom');

    expect($run->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and($run->fresh()->error_message)->toBe('boom');
});

test('ExecuteSubtaskJob::failed() marks a still-active run Failed', function () {
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => 999_999,
        'status' => AgentRunStatus::Running->value,
        'started_at' => now()->subMinutes(2),
    ]);

    (new ExecuteSubtaskJob($run->getKey()))->failed(new RuntimeException('worker died'));

    expect($run->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and($run->fresh()->error_message)->toContain('worker died');
});

test('ExecuteSubtaskJob::failed() is a no-op for terminal runs', function () {
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => 999_999,
        'status' => AgentRunStatus::Failed->value,
        'error_message' => 'original failure',
        'finished_at' => now(),
    ]);

    (new ExecuteSubtaskJob($run->getKey()))->failed(new RuntimeException('late callback'));

    expect($run->fresh()->error_message)->toBe('original failure');
});

test('GenerateTasksJob::failed() marks a still-active task-gen run Failed', function () {
    $story = Story::factory()->create();
    $run = AgentRun::factory()->create([
        'runnable_type' => Story::class,
        'runnable_id' => $story->getKey(),
        'status' => AgentRunStatus::Running->value,
        'started_at' => now()->subMinutes(2),
    ]);

    (new GenerateTasksJob($run->getKey()))->failed(new RuntimeException('worker oom'));

    expect($run->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and($run->fresh()->error_message)->toContain('worker oom');
});

test('runs:reap-stuck marks stale active runs Failed', function () {
    $stale = AgentRun::factory()->create([
        'status' => AgentRunStatus::Running->value,
        'started_at' => now()->subHours(3),
    ]);
    $fresh = AgentRun::factory()->create([
        'status' => AgentRunStatus::Running->value,
        'started_at' => now()->subMinutes(5),
    ]);
    $done = AgentRun::factory()->create([
        'status' => AgentRunStatus::Succeeded->value,
        'started_at' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
    ]);

    $this->artisan('runs:reap-stuck', ['--minutes' => 60])
        ->assertExitCode(0);

    expect($stale->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and($stale->fresh()->error_message)->toContain('Reaped')
        ->and($fresh->fresh()->status)->toBe(AgentRunStatus::Running)
        ->and($done->fresh()->status)->toBe(AgentRunStatus::Succeeded);
});

test('runs:reap-stuck --dry-run lists candidates without modifying rows', function () {
    $stale = AgentRun::factory()->create([
        'status' => AgentRunStatus::Running->value,
        'started_at' => now()->subHours(3),
    ]);

    $this->artisan('runs:reap-stuck', ['--minutes' => 60, '--dry-run' => true])
        ->assertExitCode(0);

    expect($stale->fresh()->status)->toBe(AgentRunStatus::Running);
});
