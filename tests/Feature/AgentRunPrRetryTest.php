<?php

use App\Enums\AgentRunStatus;
use App\Enums\RepoProvider;
use App\Jobs\OpenPullRequestJob;
use App\Models\AgentRun;
use App\Models\Subtask;
use App\Models\User;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
});

test('retryPullRequestOpen refuses runs that are not Succeeded', function () {
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->id,
        'status' => AgentRunStatus::Failed,
    ]);

    expect(fn () => app(ExecutionService::class)->retryPullRequestOpen($run))
        ->toThrow(RuntimeException::class, 'Succeeded');
});

test('retryPullRequestOpen refuses runs that already have a PR url', function () {
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => Subtask::factory()->create()->id,
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_url' => 'https://github.com/x/y/pull/1'],
    ]);

    expect(fn () => app(ExecutionService::class)->retryPullRequestOpen($run))
        ->toThrow(RuntimeException::class, 'already');
});

test('OpenPullRequestJob opens a fresh PR and stamps the run output', function () {
    Http::fake([
        'api.github.com/repos/*/pulls?head*' => Http::response([], 200),
        'api.github.com/repos/*/pulls' => Http::response([
            'html_url' => 'https://github.com/o/r/pull/42',
            'number' => 42,
            'id' => 999,
        ], 201),
    ]);

    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        'url' => 'https://github.com/o/r',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->tasks()->first()->subtasks()->first();

    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'working_branch' => 'specify/feature/story',
        'executor_driver' => 'fake',
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_error' => 'previous attempt blew up'],
    ]);

    (new OpenPullRequestJob($run->id))->handle();

    $fresh = $run->fresh();
    expect($fresh->output['pull_request_url'])->toBe('https://github.com/o/r/pull/42');
    expect($fresh->output['pull_request_number'])->toBe(42);
    expect($fresh->output)->not->toHaveKey('pull_request_error');
    expect($fresh->status)->toBe(AgentRunStatus::Succeeded);
});

test('OpenPullRequestJob adopts an existing open PR rather than opening a duplicate', function () {
    Http::fake([
        'api.github.com/repos/*/pulls?head*' => Http::response([
            ['html_url' => 'https://github.com/o/r/pull/7', 'number' => 7, 'id' => 70],
        ], 200),
    ]);

    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        'url' => 'https://github.com/o/r',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->tasks()->first()->subtasks()->first();

    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'working_branch' => 'specify/feature/story',
        'executor_driver' => 'fake',
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_error' => 'previous attempt blew up'],
    ]);

    (new OpenPullRequestJob($run->id))->handle();

    $fresh = $run->fresh();
    expect($fresh->output['pull_request_url'])->toBe('https://github.com/o/r/pull/7');
    expect($fresh->output['pull_request_number'])->toBe(7);
    Http::assertSentCount(1);
});

test('OpenPullRequestJob records pull_request_error on provider failure', function () {
    Http::fake([
        'api.github.com/repos/*/pulls?head*' => Http::response([], 200),
        'api.github.com/repos/*/pulls' => Http::response(['message' => 'kaboom'], 500),
    ]);

    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        'url' => 'https://github.com/o/r',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->tasks()->first()->subtasks()->first();

    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'working_branch' => 'specify/feature/story',
        'executor_driver' => 'fake',
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_error' => 'first failure'],
    ]);

    (new OpenPullRequestJob($run->id))->handle();

    $fresh = $run->fresh();
    expect($fresh->output)->toHaveKey('pull_request_error');
    expect($fresh->output['pull_request_error'])->toContain('500');
    expect($fresh->status)->toBe(AgentRunStatus::Succeeded);
    expect($fresh->output)->not->toHaveKey('pull_request_url');
});

test('Run console exposes Retry PR on Succeeded runs with pull_request_error', function () {
    $story = approvedStoryInProjectWithRepo();
    $subtask = $story->tasks()->first()->subtasks()->first();
    $repo = $story->feature->project->primaryRepo();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'working_branch' => 'specify/feature/story',
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_error' => 'rate limited'],
    ]);

    $project = $story->feature->project;
    $member = User::factory()->create();
    $project->team->addMember($member);

    $this->actingAs($member);

    Livewire::test('pages::runs.show', [
        'project' => $project->id,
        'story' => $story->id,
        'subtask' => $subtask->id,
        'run' => $run->id,
    ])
        ->call('setTab', 'pr')
        ->assertSee('Retry PR open');
});
