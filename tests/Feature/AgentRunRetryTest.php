<?php

use App\Ai\Agents\SubtaskExecutor;
use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Models\AgentRun;
use App\Models\Subtask;
use App\Models\User;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    SubtaskExecutor::fake(fn () => [
        'summary' => 'noop',
        'files_changed' => [],
        'commit_message' => 'noop',
    ]);
});

test('retrySubtaskExecution dispatches a fresh AgentRun pointing at the prior run', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();

    $first = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $story->feature->project->primaryRepo()->id,
        'status' => AgentRunStatus::Failed,
        'executor_driver' => 'fake',
    ]);

    SubtaskExecutor::fake(fn () => [
        'summary' => 'retry attempt',
        'files_changed' => ['app/A.php'],
        'commit_message' => 'retry: do the thing',
    ]);

    $retry = app(ExecutionService::class)->retrySubtaskExecution($subtask, $first);

    expect($retry->retry_of_id)->toBe($first->id);
    expect($retry->id)->not->toBe($first->id);
    expect($retry->executor_driver)->toBe('fake');
});

test('retry refuses an in-flight run', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $running = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Running,
    ]);

    expect(fn () => app(ExecutionService::class)->retrySubtaskExecution($subtask, $running))
        ->toThrow(RuntimeException::class, 'still in flight');
});

test('retry refuses when the Story is no longer Approved', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $failed = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Failed,
    ]);

    $story->forceFill(['status' => StoryStatus::ChangesRequested->value])->save();

    expect(fn () => app(ExecutionService::class)->retrySubtaskExecution($subtask, $failed))
        ->toThrow(RuntimeException::class, 'not Approved');
});

test('Run console exposes a Retry button on terminal failure runs', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Failed,
    ]);

    $project = $story->feature->project;
    $member = User::factory()->create();
    $project->team->addMember($member);

    $this->actingAs($member)
        ->get("/projects/{$project->id}/stories/{$story->id}/subtasks/{$subtask->id}/runs/{$run->id}")
        ->assertOk()
        ->assertSee('Retry');
});

test('Run console hides Retry on Succeeded runs', function () {
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
        ->assertDontSee('Retry');
});

test('Run console links a retry back to its origin run', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $origin = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Failed,
    ]);
    $retry = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Running,
        'retry_of_id' => $origin->id,
    ]);

    $project = $story->feature->project;
    $member = User::factory()->create();
    $project->team->addMember($member);

    $this->actingAs($member)
        ->get("/projects/{$project->id}/stories/{$story->id}/subtasks/{$subtask->id}/runs/{$retry->id}")
        ->assertOk()
        ->assertSee('retry of')
        ->assertSee("#{$origin->id}");
});
