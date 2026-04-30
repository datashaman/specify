<?php

use App\Ai\Agents\SubtaskExecutor;
use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    SubtaskExecutor::fake(fn () => [
        'summary' => 'noop',
        'files_changed' => [],
        'commit_message' => 'noop',
    ]);
});

function approvedStoryInProjectWithRepo(): Story
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

    $ac = $story->acceptanceCriteria()->first() ?? AcceptanceCriterion::factory()->for($story)->create();
    $task = Task::factory()->for($story)->create(['acceptance_criterion_id' => $ac->id, 'position' => 0]);
    Subtask::factory()->for($task)->create(['position' => 0, 'name' => 'only-sub']);

    $story->forceFill(['status' => StoryStatus::Draft->value])->save();
    $story->fresh()->submitForApproval();

    return $story->fresh();
}

test('subtask execution job runs the agent and marks subtask Done', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();

    SubtaskExecutor::fake(fn () => [
        'summary' => 'edited two files',
        'files_changed' => ['app/A.php', 'app/B.php'],
        'commit_message' => 'feat: do the thing',
    ]);

    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    $run = AgentRun::where('runnable_id', $subtask->id)
        ->where('runnable_type', Subtask::class)
        ->latest('id')->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->output)->toMatchArray([
            'summary' => 'edited two files',
            'commit_message' => 'feat: do the thing',
        ])
        ->and($run->diff)->toContain('app/A.php')
        ->and($subtask->fresh()->status)->toBe(TaskStatus::Done);
});

test('dispatch picks the project primary repo by default and sets working_branch', function () {
    $story = approvedStoryInProjectWithRepo();
    $task = $story->tasks()->first();
    $subtask = $task->subtasks()->first();
    $primary = $story->feature->project->primaryRepo();

    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    $run = AgentRun::where('runnable_id', $subtask->id)
        ->where('runnable_type', Subtask::class)
        ->latest('id')->firstOrFail();
    $expectedBranch = 'specify/'.Str::slug($story->feature->project->name).'/'.Str::slug($story->name);
    expect($run->repo_id)->toBe($primary->id)
        ->and($run->working_branch)->toBe($expectedBranch);
});

test('dispatch accepts an explicit repo override', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $project = $story->feature->project;
    $workspace = $project->team->workspace;
    $other = Repo::factory()->for($workspace)->create();
    $project->attachRepo($other, role: 'worker');

    app(ExecutionService::class)->dispatchSubtaskExecution($subtask, repo: $other);

    $run = AgentRun::where('runnable_id', $subtask->id)
        ->where('runnable_type', Subtask::class)
        ->latest('id')->firstOrFail();
    expect($run->repo_id)->toBe($other->id);
});

test('agent failure marks subtask Blocked and run Failed', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();

    SubtaskExecutor::fake(function () {
        throw new RuntimeException('rate limited');
    });

    try {
        app(ExecutionService::class)->dispatchSubtaskExecution($subtask);
    } catch (Throwable $e) {
        // sync queue rethrows
    }

    $run = AgentRun::where('runnable_id', $subtask->id)
        ->where('runnable_type', Subtask::class)
        ->latest('id')->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error_message)->toContain('rate limited')
        ->and($subtask->fresh()->status)->toBe(TaskStatus::Blocked);
});

test('full story execution: subtasks succeed and story flips to Done', function () {
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

    $ac1 = $story->acceptanceCriteria()->first();
    $ac2 = AcceptanceCriterion::factory()->for($story)->create(['position' => 2]);

    $taskA = Task::factory()->for($story)->create(['name' => 'a', 'position' => 0, 'acceptance_criterion_id' => $ac1->id]);
    $taskB = Task::factory()->for($story)->create(['name' => 'b', 'position' => 1, 'acceptance_criterion_id' => $ac2->id]);
    Subtask::factory()->for($taskA)->create(['position' => 0]);
    Subtask::factory()->for($taskB)->create(['position' => 0]);
    $taskB->addDependency($taskA);

    $story->forceFill(['status' => StoryStatus::Draft->value])->save();
    $story->fresh()->submitForApproval();

    expect($story->fresh()->status)->toBe(StoryStatus::Done)
        ->and($taskA->fresh()->status)->toBe(TaskStatus::Done)
        ->and($taskB->fresh()->status)->toBe(TaskStatus::Done);
});

test('agent prompt includes repo URL and working branch', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();

    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    SubtaskExecutor::assertPrompted(function ($prompt) {
        return str_contains($prompt->prompt, 'https://github.com/')
            && str_contains($prompt->prompt, 'specify/');
    });
});
