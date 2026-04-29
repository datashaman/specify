<?php

use App\Enums\RepoProvider;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function webhookRun(Repo $repo, int $prNumber): AgentRun
{
    $task = Task::factory()->create();

    return AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $task->id,
        'repo_id' => $repo->id,
        'output' => ['pull_request_number' => $prNumber, 'pull_request_url' => 'https://github.com/o/r/pull/'.$prNumber],
    ]);
}

function postWebhook(Repo $repo, string $secret, string $event, array $payload)
{
    $body = json_encode($payload);
    $sig = 'sha256='.hash_hmac('sha256', $body, $secret);

    return test()->call(
        method: 'POST',
        uri: route('webhooks.github', $repo),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ],
        content: $body,
    );
}

test('rejects requests without a valid signature', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
    ]);

    test()->postJson(route('webhooks.github', $repo), ['x' => 1])
        ->assertStatus(401);
});

test('marks merged PRs on the matching agent run', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
    ]);
    $run = webhookRun($repo, 42);

    postWebhook($repo, 's3cret', 'pull_request', [
        'action' => 'closed',
        'pull_request' => [
            'number' => 42,
            'merged' => true,
            'merged_at' => '2026-04-29T12:00:00Z',
            'closed_at' => '2026-04-29T12:00:00Z',
        ],
    ])->assertOk()->assertJson(['matched' => true, 'run_id' => $run->id]);

    expect($run->fresh()->output)
        ->toMatchArray([
            'pull_request_number' => 42,
            'pull_request_action' => 'closed',
            'pull_request_merged' => true,
            'pull_request_merged_at' => '2026-04-29T12:00:00Z',
        ]);
});

test('records closed-without-merge state', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
    ]);
    $run = webhookRun($repo, 9);

    postWebhook($repo, 's3cret', 'pull_request', [
        'action' => 'closed',
        'pull_request' => [
            'number' => 9,
            'merged' => false,
            'closed_at' => '2026-04-29T13:00:00Z',
            'merged_at' => null,
        ],
    ])->assertOk();

    expect($run->fresh()->output['pull_request_merged'])->toBeFalse()
        ->and($run->fresh()->output['pull_request_closed_at'])->toBe('2026-04-29T13:00:00Z');
});

test('ignores unrelated event types', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
    ]);

    postWebhook($repo, 's3cret', 'push', ['ref' => 'refs/heads/main'])
        ->assertOk()
        ->assertJson(['ignored' => true]);
});

test('returns matched=false when PR number does not match any run', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
    ]);

    postWebhook($repo, 's3cret', 'pull_request', [
        'action' => 'closed',
        'pull_request' => ['number' => 999, 'merged' => true],
    ])->assertOk()->assertJson(['matched' => false]);
});
