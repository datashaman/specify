<?php

use App\Ai\Agents\TaskExecutor;
use App\Enums\AgentRunStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\Plan;
use App\Models\Repo;
use App\Models\Task;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
});

function approvedPlanInProjectWithRepo(): Plan
{
    $story = makeStory();
    $project = $story->feature->project;
    $workspace = $project->team->workspace;
    $repo = Repo::factory()->for($workspace)->create();
    $project->attachRepo($repo);

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 0,
    ]);

    $plan = Plan::factory()->for($story)->create();
    Task::factory()->for($plan)->create(['name' => 'only-task', 'position' => 0]);
    $plan->submitForApproval();

    return $plan->fresh();
}

test('task execution job runs the agent and marks task Done', function () {
    $plan = approvedPlanInProjectWithRepo();
    $task = $plan->tasks()->first();

    TaskExecutor::fake(fn () => [
        'summary' => 'edited two files',
        'files_changed' => ['app/A.php', 'app/B.php'],
        'commit_message' => 'feat: do the thing',
    ]);

    app(ExecutionService::class)->dispatchTaskExecution($task);

    $run = AgentRun::where('runnable_id', $task->id)->latest('id')->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->output)->toMatchArray([
            'summary' => 'edited two files',
            'commit_message' => 'feat: do the thing',
        ])
        ->and($run->diff)->toContain('app/A.php')
        ->and($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('dispatch picks the project primary repo by default and sets working_branch', function () {
    $plan = approvedPlanInProjectWithRepo();
    $task = $plan->tasks()->first();
    $project = $plan->story->feature->project;
    $primary = $project->primaryRepo();

    TaskExecutor::fake(fn () => [
        'summary' => 'ok',
        'files_changed' => [],
        'commit_message' => 'noop',
    ]);

    app(ExecutionService::class)->dispatchTaskExecution($task);

    $run = AgentRun::where('runnable_id', $task->id)->latest('id')->firstOrFail();
    expect($run->repo_id)->toBe($primary->id)
        ->and($run->working_branch)->toContain('specify/story-')
        ->and($run->working_branch)->toContain('-task-0');
});

test('dispatch accepts an explicit repo override', function () {
    $plan = approvedPlanInProjectWithRepo();
    $task = $plan->tasks()->first();
    $project = $plan->story->feature->project;
    $workspace = $project->team->workspace;
    $other = Repo::factory()->for($workspace)->create();
    $project->attachRepo($other, role: 'worker');

    TaskExecutor::fake(fn () => [
        'summary' => 'ok',
        'files_changed' => [],
        'commit_message' => 'noop',
    ]);

    app(ExecutionService::class)->dispatchTaskExecution($task, repo: $other);

    $run = AgentRun::where('runnable_id', $task->id)->latest('id')->firstOrFail();
    expect($run->repo_id)->toBe($other->id);
});

test('agent failure marks task Blocked and run Failed', function () {
    $plan = approvedPlanInProjectWithRepo();
    $task = $plan->tasks()->first();

    TaskExecutor::fake(function () {
        throw new RuntimeException('rate limited');
    });

    try {
        app(ExecutionService::class)->dispatchTaskExecution($task);
    } catch (Throwable $e) {
        // sync queue rethrows
    }

    $run = AgentRun::where('runnable_id', $task->id)->latest('id')->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error_message)->toContain('rate limited')
        ->and($task->fresh()->status)->toBe(TaskStatus::Blocked);
});

test('full plan execution: tasks succeed in order and plan flips to Done', function () {
    $story = makeStory();
    $project = $story->feature->project;
    $workspace = $project->team->workspace;
    $repo = Repo::factory()->for($workspace)->create();
    $project->attachRepo($repo);
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 0,
    ]);

    $plan = Plan::factory()->for($story)->create();
    $a = Task::factory()->for($plan)->create(['name' => 'a', 'position' => 0]);
    $b = Task::factory()->for($plan)->create(['name' => 'b', 'position' => 1]);
    $b->addDependency($a);
    $plan->submitForApproval();
    $plan = $plan->fresh();

    TaskExecutor::fake(fn () => [
        'summary' => 'done',
        'files_changed' => ['x.php'],
        'commit_message' => 'feat: x',
    ]);

    app(ExecutionService::class)->startPlanExecution($plan);

    expect($plan->fresh()->status)->toBe(PlanStatus::Done)
        ->and($a->fresh()->status)->toBe(TaskStatus::Done)
        ->and($b->fresh()->status)->toBe(TaskStatus::Done);
});

test('agent prompt includes repo URL and working branch', function () {
    $plan = approvedPlanInProjectWithRepo();
    $task = $plan->tasks()->first();

    TaskExecutor::fake(fn () => [
        'summary' => 'ok',
        'files_changed' => [],
        'commit_message' => 'noop',
    ]);

    app(ExecutionService::class)->dispatchTaskExecution($task);

    TaskExecutor::assertPrompted(function ($prompt) {
        return str_contains($prompt->prompt, 'https://github.com/example/')
            && str_contains($prompt->prompt, 'specify/story-');
    });
});
