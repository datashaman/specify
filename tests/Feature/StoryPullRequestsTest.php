<?php

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function storyWithSubtaskAndRun(array $output, array $runOverrides = []): array
{
    $story = Story::factory()->create();
    $task = Task::factory()->for($story)->create();
    $subtask = Subtask::factory()->for($task)->create();
    $run = AgentRun::factory()->create(array_merge([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
        'kind' => AgentRunKind::Execute,
        'output' => $output,
    ], $runOverrides));

    return ['story' => $story, 'subtask' => $subtask, 'run' => $run];
}

test('pullRequests returns empty when no run has opened a PR', function () {
    $s = storyWithSubtaskAndRun(['summary' => 'noop']);

    expect($s['story']->pullRequests())->toBeEmpty();
    expect($s['story']->primaryPullRequest())->toBeNull();
});

test('pullRequests surfaces a single-driver PR as primary', function () {
    $s = storyWithSubtaskAndRun([
        'pull_request_url' => 'https://github.com/o/r/pull/42',
        'pull_request_number' => 42,
    ], ['executor_driver' => 'cli']);

    $prs = $s['story']->pullRequests();
    expect($prs)->toHaveCount(1);
    expect($prs->first()['url'])->toBe('https://github.com/o/r/pull/42');
    expect($prs->first()['driver'])->toBe('cli');

    $primary = $s['story']->primaryPullRequest();
    expect($primary)->not->toBeNull();
    expect($primary['url'])->toBe('https://github.com/o/r/pull/42');
});

test('pullRequests deduplicates retries that adopted the same upstream PR', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->for($story)->create();
    $subtask = Subtask::factory()->for($task)->create();

    // Two AgentRuns on the same Subtask both adopting the same PR — e.g.
    // the original run's PR-open failed and a later retry adopted it via
    // findOpenPullRequest. The dedup keeps only the most recent.
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'kind' => AgentRunKind::Execute,
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_url' => 'https://github.com/o/r/pull/9'],
    ]);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'kind' => AgentRunKind::Execute,
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_url' => 'https://github.com/o/r/pull/9'],
    ]);

    expect($story->pullRequests())->toHaveCount(1);
});

test('pullRequests in race mode surfaces every sibling and primary is null until merge', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->for($story)->create();
    $subtask = Subtask::factory()->for($task)->create();

    foreach (['cli' => 21, 'specify' => 22, 'codex' => 23] as $driver => $number) {
        AgentRun::factory()->create([
            'runnable_type' => Subtask::class,
            'runnable_id' => $subtask->id,
            'status' => AgentRunStatus::Succeeded,
            'kind' => AgentRunKind::Execute,
            'executor_driver' => $driver,
            'working_branch' => 'specify/feature/story-by-'.$driver,
            'output' => [
                'pull_request_url' => 'https://github.com/o/r/pull/'.$number,
                'pull_request_number' => $number,
            ],
        ]);
    }

    $prs = $story->pullRequests();
    expect($prs)->toHaveCount(3);
    // Ordered most-recent first (sorted by run id desc); insertion order
    // was cli, specify, codex so the freshest run comes back first.
    expect($prs->pluck('driver')->all())->toMatchArray(['codex', 'specify', 'cli']);

    // Pre-merge race mode: no canonical PR.
    expect($story->primaryPullRequest())->toBeNull();
});

test('primaryPullRequest hoists the merged sibling and pullRequests sorts it first', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->for($story)->create();
    $subtask = Subtask::factory()->for($task)->create();

    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
        'kind' => AgentRunKind::Execute,
        'executor_driver' => 'cli',
        'output' => [
            'pull_request_url' => 'https://github.com/o/r/pull/100',
            'pull_request_number' => 100,
            'pull_request_merged' => false,
        ],
    ]);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
        'kind' => AgentRunKind::Execute,
        'executor_driver' => 'specify',
        'output' => [
            'pull_request_url' => 'https://github.com/o/r/pull/101',
            'pull_request_number' => 101,
            'pull_request_merged' => true,
        ],
    ]);

    $prs = $story->pullRequests();
    expect($prs->first()['merged'])->toBeTrue();
    expect($prs->first()['number'])->toBe(101);

    $primary = $story->primaryPullRequest();
    expect($primary['url'])->toBe('https://github.com/o/r/pull/101');
});

test('pullRequests excludes RespondToReview-kind runs (those just push commits, not PRs)', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->for($story)->create();
    $subtask = Subtask::factory()->for($task)->create();

    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
        'kind' => AgentRunKind::RespondToReview,
        'output' => [
            'pull_request_url' => 'https://github.com/o/r/pull/55',
            'pull_request_number' => 55,
        ],
    ]);

    expect($story->pullRequests())->toBeEmpty();
});
