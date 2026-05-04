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
use App\Services\Executors\FakeExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Build a Story → AC → Task → Subtask, attach a Repo, and submit for
 * approval through the existing factory wiring used elsewhere in the
 * suite. Returns the single Subtask we'll race against.
 */
function approvedSubtaskForRace(): Subtask
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
    $task = Task::factory()->forStory($story)->create(['acceptance_criterion_id' => $ac->id, 'position' => 0]);
    Subtask::factory()->for($task)->create(['position' => 0, 'name' => 'race-me']);

    $story->forceFill(['status' => StoryStatus::Draft->value])->save();
    $story->fresh()->submitForApproval();

    return $story->fresh()->tasks()->first()->subtasks()->first();
}

beforeEach(function () {
    config(['queue.default' => 'sync']);
    // Register a second non-WD-needing driver alongside `fake` so the race
    // can fan out without spinning up clones or hitting any HTTP surface.
    config(['specify.executor.drivers.fake-2' => ['class' => FakeExecutor::class]]);
    SubtaskExecutor::fake(fn () => [
        'summary' => 'noop',
        'files_changed' => ['app/Foo.php'],
        'commit_message' => 'noop',
    ]);
});

test('race fan-out creates one AgentRun per driver with distinct branches and drivers', function () {
    config(['specify.executor.race' => ['fake', 'fake-2']]);

    $subtask = approvedSubtaskForRace();
    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    $runs = AgentRun::where('runnable_type', Subtask::class)
        ->where('runnable_id', $subtask->getKey())
        ->orderBy('id')
        ->get();

    expect($runs)->toHaveCount(2);
    expect($runs->pluck('executor_driver')->all())->toBe(['fake', 'fake-2']);
    $branches = $runs->pluck('working_branch')->all();
    expect($branches[0])->toEndWith('-by-fake');
    expect($branches[1])->toEndWith('-by-fake-2');
    expect($branches[0])->not()->toBe($branches[1]);
});

test('non-race dispatch creates one AgentRun stamped with the default driver and unsuffixed branch', function () {
    config(['specify.executor.race' => []]);

    $subtask = approvedSubtaskForRace();
    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    $runs = AgentRun::where('runnable_type', Subtask::class)
        ->where('runnable_id', $subtask->getKey())
        ->get();

    expect($runs)->toHaveCount(1);
    expect($runs->first()->executor_driver)->toBe('fake');
    expect($runs->first()->working_branch)->not->toContain('-by-');
});

test('cascade waits for every sibling: first success does NOT mark Subtask Done', function () {
    Queue::fake(); // hold ExecuteSubtaskJob so we can drive finalisation by hand
    config(['specify.executor.race' => ['fake', 'fake-2']]);
    $subtask = approvedSubtaskForRace();
    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    [$first, $second] = AgentRun::where('runnable_type', Subtask::class)
        ->where('runnable_id', $subtask->getKey())
        ->orderBy('id')
        ->get()
        ->all();
    expect($first->status)->toBe(AgentRunStatus::Queued);
    expect($second->status)->toBe(AgentRunStatus::Queued);

    app(ExecutionService::class)->markSucceeded($first->fresh(), ['summary' => 'won'], null);
    expect($subtask->fresh()->status)->toBe(TaskStatus::Pending);

    app(ExecutionService::class)->markSucceeded($second->fresh(), ['summary' => 'also-won'], null);
    expect($subtask->fresh()->status)->toBe(TaskStatus::Done);
});

test('one success + one failure marks the Subtask Done (any winner takes it)', function () {
    Queue::fake();
    config(['specify.executor.race' => ['fake', 'fake-2']]);
    $subtask = approvedSubtaskForRace();
    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    [$first, $second] = AgentRun::where('runnable_type', Subtask::class)
        ->where('runnable_id', $subtask->getKey())
        ->orderBy('id')
        ->get()
        ->all();

    app(ExecutionService::class)->markFailed($first->fresh(), 'driver crashed');
    expect($subtask->fresh()->status)->toBe(TaskStatus::Pending);

    app(ExecutionService::class)->markSucceeded($second->fresh(), ['summary' => 'won'], null);
    expect($subtask->fresh()->status)->toBe(TaskStatus::Done);
});

test('every sibling failed marks the Subtask Blocked, no cascade', function () {
    Queue::fake();
    config(['specify.executor.race' => ['fake', 'fake-2']]);
    $subtask = approvedSubtaskForRace();
    app(ExecutionService::class)->dispatchSubtaskExecution($subtask);

    [$first, $second] = AgentRun::where('runnable_type', Subtask::class)
        ->where('runnable_id', $subtask->getKey())
        ->orderBy('id')
        ->get()
        ->all();

    app(ExecutionService::class)->markFailed($first->fresh(), 'driver crashed');
    expect($subtask->fresh()->status)->toBe(TaskStatus::Pending);

    app(ExecutionService::class)->markFailed($second->fresh(), 'driver crashed');
    expect($subtask->fresh()->status)->toBe(TaskStatus::Blocked);
});
