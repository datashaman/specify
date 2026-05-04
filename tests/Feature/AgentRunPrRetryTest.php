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

test('retryPullRequestOpen dispatches the OpenPullRequestJob end-to-end', function () {
    Http::fake([
        'api.github.com/*pulls*' => Http::response([
            [
                'html_url' => 'https://github.com/o/r/pull/77',
                'number' => 77,
                'id' => 7,
                'head' => ['ref' => 'specify/feature/story', 'repo' => ['full_name' => 'o/r']],
            ],
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
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'working_branch' => 'specify/feature/story',
        'executor_driver' => 'fake',
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_error' => 'rate limited'],
    ]);

    app(ExecutionService::class)->retryPullRequestOpen($run);

    $fresh = $run->fresh();
    expect($fresh->output['pull_request_url'])->toBe('https://github.com/o/r/pull/77');
});

test('OpenPullRequestJob bails inside the lock when a concurrent retry already stamped the URL', function () {
    // Two queued retries: first one stamps the URL while the second is
    // still queued; the second wakes up, acquires the lock, must bail
    // before calling create() — without this guard, providers that
    // return null from findOpenPullRequest (Bitbucket / GitLab) would
    // open duplicate MRs.
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'should-not-be-called', 'number' => 0, 'id' => 0], 201),
    ]);

    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        'url' => 'https://github.com/o/r',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'working_branch' => 'specify/feature/story',
        'executor_driver' => 'fake',
        'status' => AgentRunStatus::Succeeded,
        'output' => [
            'pull_request_error' => 'first attempt failed',
            'pull_request_url' => 'https://github.com/o/r/pull/100',
        ],
    ]);

    (new OpenPullRequestJob($run->id))->handle();

    Http::assertNothingSent();
    expect($run->fresh()->output['pull_request_url'])->toBe('https://github.com/o/r/pull/100');
});

test('OpenPullRequestJob records find() exceptions as pull_request_error instead of crashing the worker', function () {
    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        // Malformed URL: parseOwnerRepo throws a RuntimeException — the
        // job must capture this as a normal pr_retry failure rather than
        // letting the exception escape the queue worker.
        'url' => 'not-a-valid-url',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

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
    expect($fresh->status)->toBe(AgentRunStatus::Succeeded);
    expect($fresh->output['pull_request_error'])->toContain('Unable to parse');
});

test('OpenPullRequestJob opens a fresh PR and stamps the run output', function () {
    Http::fake(function ($request) {
        // GET (find) → empty list; POST (create) → 201 with the new PR.
        if ($request->method() === 'GET') {
            return Http::response([], 200);
        }

        return Http::response([
            'html_url' => 'https://github.com/o/r/pull/42',
            'number' => 42,
            'id' => 999,
        ], 201);
    });

    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        'url' => 'https://github.com/o/r',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

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
        'api.github.com/*pulls*' => Http::response([
            [
                'html_url' => 'https://github.com/o/r/pull/7',
                'number' => 7,
                'id' => 70,
                'head' => ['ref' => 'specify/feature/story', 'repo' => ['full_name' => 'o/r']],
            ],
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
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

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
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

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
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();
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

test('GithubPullRequestProvider::findOpenPullRequest skips fork PRs whose head.ref collides with a same-repo branch', function () {
    // Same head.ref, different head repo — must be ignored. Otherwise a
    // fork PR for the same branch name would be adopted and stamped on
    // a Specify run that opened a PR on the destination repo, causing
    // notifications to point at the wrong PR.
    Http::fake(function ($request) {
        if ($request->method() === 'GET') {
            return Http::response([
                [
                    'html_url' => 'https://github.com/forker/r/pull/1',
                    'number' => 1,
                    'id' => 1,
                    'head' => [
                        'ref' => 'specify/feature/story',
                        'repo' => ['full_name' => 'forker/r'],
                    ],
                ],
            ], 200);
        }

        // POST = createPullRequest. Simulate GitHub's "PR already exists"
        // 422 — a real test for "fork PR was not adopted" is that we
        // actually attempted to create.
        return Http::response([
            'message' => 'Validation Failed',
            'errors' => [['message' => 'A pull request already exists for o:specify/feature/story.']],
        ], 422);
    });

    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        'url' => 'https://github.com/o/r',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

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
    expect($fresh->output)->not->toHaveKey('pull_request_url');
    expect($fresh->output['pull_request_error'])->toContain('422');
});

test('GithubPullRequestProvider::findOpenPullRequest follows Link rel="next" pagination to find a match on a later page', function () {
    // First page: 1 unrelated PR + a Link header pointing to page 2.
    // Second page: the matching PR. The provider must follow the Link
    // header rather than giving up after page 1.
    Http::fakeSequence('api.github.com/*pulls*')
        ->push([
            [
                'html_url' => 'https://github.com/o/r/pull/1',
                'number' => 1,
                'id' => 1,
                'head' => ['ref' => 'unrelated/branch', 'repo' => ['full_name' => 'o/r']],
            ],
        ], 200, [
            'Link' => '<https://api.github.com/repositories/1/pulls?state=open&per_page=100&page=2>; rel="next"',
        ])
        ->push([
            [
                'html_url' => 'https://github.com/o/r/pull/2',
                'number' => 2,
                'id' => 2,
                'head' => ['ref' => 'specify/feature/story', 'repo' => ['full_name' => 'o/r']],
            ],
        ], 200);

    $story = approvedStoryInProjectWithRepo();
    $repo = $story->feature->project->primaryRepo();
    $repo->forceFill([
        'provider' => RepoProvider::Github,
        'access_token' => 'tok',
        'url' => 'https://github.com/o/r',
        'default_branch' => 'main',
    ])->save();
    $subtask = $story->currentPlanTasks()->first()->subtasks()->first();

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

    expect($run->fresh()->output['pull_request_url'])->toBe('https://github.com/o/r/pull/2');
});
