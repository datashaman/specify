<?php

use App\Ai\Agents\SubtaskExecutor;
use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\Repo;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\ApprovalService;
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

function approvedStoryScene(): array
{
    $story = makeStory();
    $project = $story->feature->project;
    $workspace = $project->team->workspace;
    $repo = Repo::factory()->for($workspace)->create();
    $project->attachRepo($repo);

    $ac = $story->acceptanceCriteria()->first();
    $task = Task::factory()->for($story)->create(['position' => 0, 'acceptance_criterion_id' => $ac->id]);
    Subtask::factory()->for($task)->create(['position' => 0]);

    return ['story' => $story->fresh(), 'project' => $project, 'task' => $task];
}

test('approving the threshold flips story to Approved and dispatches actionable subtasks', function () {
    ['story' => $story, 'project' => $project] = approvedStoryScene();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 1,
    ]);

    $story->fresh()->submitForApproval();
    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);

    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($story->fresh(), $approver, ApprovalDecision::Approve);

    expect(AgentRun::where('runnable_type', Subtask::class)->count())->toBeGreaterThan(0);
});

test('required_approvals=0 auto-approves and immediately starts execution', function () {
    ['story' => $story, 'project' => $project] = approvedStoryScene();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 0,
    ]);

    $story->fresh()->submitForApproval();

    expect($story->fresh()->status)->toBe(StoryStatus::Done)
        ->and(AgentRun::where('runnable_type', Subtask::class)->count())->toBe(1);
});

test('Reject does not start execution', function () {
    ['story' => $story, 'project' => $project] = approvedStoryScene();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 1,
    ]);

    $story->fresh()->submitForApproval();
    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($story->fresh(), $approver, ApprovalDecision::Reject);

    expect($story->fresh()->status)->toBe(StoryStatus::Rejected)
        ->and(AgentRun::where('runnable_type', Subtask::class)->count())->toBe(0);
});

test('ChangesRequested does not start execution', function () {
    ['story' => $story, 'project' => $project] = approvedStoryScene();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 1,
    ]);

    $story->fresh()->submitForApproval();
    $approver = User::factory()->create();
    app(ApprovalService::class)->recordDecision($story->fresh(), $approver, ApprovalDecision::ChangesRequested);

    expect($story->fresh()->status)->toBe(StoryStatus::ChangesRequested)
        ->and(AgentRun::where('runnable_type', Subtask::class)->count())->toBe(0);
});
