<?php

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Jobs\ResolveConflictsJob;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Story + GitHub PR in conflicted (mergeable=false) state for dispatch / UI tests.
 *
 * @return array{
 *     user: User,
 *     project: Project,
 *     story: Story,
 *     subtask: Subtask,
 *     repo: Repo,
 *     run: AgentRun,
 * }
 */
function conflictResolutionScene(bool $mergeable = false): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Owner);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::Approved,
        'created_by_id' => $user->id,
    ]);

    $repo = Repo::factory()->for($ws)->create([
        'url' => 'https://github.com/o/r.git',
        'access_token' => 'gh-test-token',
    ]);
    $project->attachRepo($repo);

    $ac = AcceptanceCriterion::factory()->for($story)->create();
    $task = Task::factory()->forStory($story)->create(['acceptance_criterion_id' => $ac->id]);
    $subtask = Subtask::factory()->for($task)->create();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'working_branch' => 'specify/feature/story',
        'executor_driver' => 'laravel-ai',
        'kind' => AgentRunKind::Execute,
        'status' => AgentRunStatus::Succeeded,
        'output' => [
            'pull_request_url' => 'https://github.com/o/r/pull/99',
            'pull_request_number' => 99,
            'pull_request_merged' => false,
        ],
    ]);

    Http::fake([
        'https://api.github.com/repos/o/r/pulls/99' => Http::response([
            'mergeable' => $mergeable,
            'mergeable_state' => $mergeable ? 'clean' : 'dirty',
        ], 200),
    ]);

    return [
        'user' => $user,
        'project' => $project,
        'story' => $story->fresh(),
        'subtask' => $subtask,
        'repo' => $repo,
        'run' => $run,
    ];
}

test('pullRequests enriches GitHub PRs with mergeability from the probe', function () {
    $ctx = conflictResolutionScene(mergeable: false);

    $prs = $ctx['story']->pullRequests();
    expect($prs)->toHaveCount(1)
        ->and($prs->first()['mergeable'])->toBeFalse()
        ->and($prs->first()['mergeable_state'])->toBe('dirty');
});

test('dispatchConflictResolution queues a ResolveConflicts AgentRun', function () {
    Queue::fake();
    $ctx = conflictResolutionScene(mergeable: false);

    $result = app(ExecutionService::class)->dispatchConflictResolution($ctx['story']);

    expect($result['status'])->toBe('dispatched')
        ->and(AgentRun::where('kind', AgentRunKind::ResolveConflicts)->count())->toBe(1);

    $newRun = AgentRun::where('kind', AgentRunKind::ResolveConflicts)->firstOrFail();
    expect($newRun->output['pull_request_number'])->toBe(99)
        ->and((int) $newRun->output['origin_run_id'])->toBe($ctx['run']->id)
        ->and($newRun->executor_driver)->toBe($ctx['run']->executor_driver);

    Queue::assertPushed(ResolveConflictsJob::class, fn ($job) => $job->agentRunId === $newRun->id);
});

test('dispatchConflictResolution does nothing when GitHub reports mergeable', function () {
    Queue::fake();
    $ctx = conflictResolutionScene(mergeable: true);

    $result = app(ExecutionService::class)->dispatchConflictResolution($ctx['story']);

    expect($result['status'])->toBe('not_applicable');
    Queue::assertNothingPushed();
});

test('dispatchConflictResolution returns max_cycles_reached after cap', function () {
    Queue::fake();
    $ctx = conflictResolutionScene(mergeable: false);
    config(['specify.conflict_resolution.max_cycles_per_pr' => 2]);

    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $ctx['subtask']->id,
        'repo_id' => $ctx['repo']->id,
        'kind' => AgentRunKind::ResolveConflicts,
        'status' => AgentRunStatus::Failed,
        'output' => ['pull_request_number' => 99, 'origin_run_id' => $ctx['run']->id],
    ]);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $ctx['subtask']->id,
        'repo_id' => $ctx['repo']->id,
        'kind' => AgentRunKind::ResolveConflicts,
        'status' => AgentRunStatus::Failed,
        'output' => ['pull_request_number' => 99, 'origin_run_id' => $ctx['run']->id],
    ]);

    $result = app(ExecutionService::class)->dispatchConflictResolution($ctx['story']->fresh());

    expect($result['status'])->toBe('max_cycles_reached');
    Queue::assertNothingPushed();
});

test('cascade gate ignores ResolveConflicts runs', function () {
    $ctx = conflictResolutionScene(mergeable: false);
    $ctx['subtask']->forceFill(['status' => TaskStatus::Done->value])->save();

    $conflictRun = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $ctx['subtask']->id,
        'repo_id' => $ctx['repo']->id,
        'kind' => AgentRunKind::ResolveConflicts,
        'status' => AgentRunStatus::Running,
    ]);

    app(ExecutionService::class)->markFailed($conflictRun, 'merge agent failed');

    expect($ctx['subtask']->fresh()->status->value)->toBe('done');
});

test('markFailed is idempotent when the run is already terminal', function () {
    $ctx = conflictResolutionScene(mergeable: false);
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $ctx['subtask']->id,
        'repo_id' => $ctx['repo']->id,
        'kind' => AgentRunKind::Execute,
        'status' => AgentRunStatus::Failed,
        'error_message' => 'first',
        'finished_at' => now(),
    ]);

    app(ExecutionService::class)->markFailed($run, 'second');

    $run->refresh();
    expect($run->error_message)->toBe('first');
});

test('story show exposes Resolve conflicts button when PR is conflicted and user can approve', function () {
    $ctx = conflictResolutionScene(mergeable: false);
    $this->actingAs($ctx['user']);

    Livewire::test('pages::stories.show', [
        'project' => $ctx['project']->id,
        'story' => $ctx['story']->id,
    ])
        ->assertSee('Resolve conflicts (AI)')
        ->assertSee('conflicted');
});

test('story show hides Resolve conflicts button for members without approve permission', function () {
    $ctx = conflictResolutionScene(mergeable: false);
    $member = User::factory()->create();
    $ctx['story']->feature->project->team->addMember($member, TeamRole::Member);
    $this->actingAs($member);

    Livewire::test('pages::stories.show', [
        'project' => $ctx['project']->id,
        'story' => $ctx['story']->id,
    ])
        ->assertDontSee('Resolve conflicts (AI)');
});
