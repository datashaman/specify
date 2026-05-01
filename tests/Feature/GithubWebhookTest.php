<?php

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Enums\RepoProvider;
use App\Enums\TaskStatus;
use App\Jobs\RespondToPrReviewJob;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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

test('logs every delivery as a WebhookEvent including invalid signatures', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
    ]);
    $run = webhookRun($repo, 7);

    test()->postJson(route('webhooks.github', $repo), ['x' => 1])->assertStatus(401);
    postWebhook($repo, 's3cret', 'pull_request', [
        'action' => 'closed',
        'pull_request' => ['number' => 7, 'merged' => true],
    ])->assertOk();

    $events = WebhookEvent::query()->where('repo_id', $repo->id)->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->signature_valid)->toBeFalse()
        ->and($events[1]->signature_valid)->toBeTrue()
        ->and($events[1]->event)->toBe('pull_request')
        ->and($events[1]->action)->toBe('closed')
        ->and($events[1]->matched_run_id)->toBe($run->id);
});

function reviewPayload(int $prNumber, string $body = 'fix this please'): array
{
    return [
        'action' => 'submitted',
        'review' => [
            'state' => 'changes_requested',
            'body' => $body,
            'submitted_at' => '2026-05-01T15:00:00Z',
        ],
        'pull_request' => ['number' => $prNumber],
    ];
}

function reviewSubtaskRun(Repo $repo, int $prNumber, string $branch = 'specify/feature/story'): AgentRun
{
    $subtask = Subtask::factory()->for(Task::factory())->create();

    return AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'repo_id' => $repo->id,
        'kind' => AgentRunKind::Execute->value,
        'working_branch' => $branch,
        'executor_driver' => 'fake',
        'output' => ['pull_request_number' => $prNumber, 'pull_request_url' => 'https://github.com/o/r/pull/'.$prNumber],
    ]);
}

test('pull_request_review dispatches a RespondToReview run when the repo opted in (ADR-0008)', function () {
    Queue::fake();
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
        'review_response_enabled' => true,
        'max_review_response_cycles' => 3,
    ]);
    $origin = reviewSubtaskRun($repo, 11);

    postWebhook($repo, 's3cret', 'pull_request_review', reviewPayload(11))
        ->assertOk()
        ->assertJson(['matched' => true, 'dispatched' => true, 'cycle' => 1]);

    $newRun = AgentRun::where('repo_id', $repo->id)
        ->where('kind', AgentRunKind::RespondToReview->value)
        ->latest('id')->firstOrFail();

    expect($newRun->runnable_type)->toBe($origin->runnable_type)
        ->and($newRun->runnable_id)->toBe($origin->runnable_id)
        ->and($newRun->working_branch)->toBe($origin->working_branch)
        ->and($newRun->status)->toBe(AgentRunStatus::Queued)
        ->and($newRun->output['pull_request_number'])->toBe(11)
        ->and($newRun->output['origin_run_id'])->toBe($origin->id);

    Queue::assertPushed(RespondToPrReviewJob::class, fn ($job) => $job->agentRunId === $newRun->id);
});

test('pull_request_review does NOT dispatch when the repo has not opted in', function () {
    Queue::fake();
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
        'review_response_enabled' => false,
    ]);
    reviewSubtaskRun($repo, 12);

    postWebhook($repo, 's3cret', 'pull_request_review', reviewPayload(12))
        ->assertOk()
        ->assertJson(['matched' => true, 'dispatched' => false, 'reason' => 'review_response_disabled']);

    expect(AgentRun::where('kind', AgentRunKind::RespondToReview->value)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('pull_request_review respects max_review_response_cycles cap', function () {
    Queue::fake();
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
        'review_response_enabled' => true,
        'max_review_response_cycles' => 2,
    ]);
    $origin = reviewSubtaskRun($repo, 13);
    foreach (range(1, 2) as $i) {
        AgentRun::create([
            'runnable_type' => $origin->runnable_type,
            'runnable_id' => $origin->runnable_id,
            'repo_id' => $repo->id,
            'kind' => AgentRunKind::RespondToReview->value,
            'status' => AgentRunStatus::Succeeded->value,
            'output' => ['pull_request_number' => 13],
        ]);
    }

    postWebhook($repo, 's3cret', 'pull_request_review', reviewPayload(13))
        ->assertOk()
        ->assertJson(['matched' => true, 'dispatched' => false, 'reason' => 'max_cycles_reached']);

    Queue::assertNothingPushed();
});

test('duplicate webhook delivery is idempotent on X-GitHub-Delivery', function () {
    Queue::fake();
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
        'review_response_enabled' => true,
    ]);
    reviewSubtaskRun($repo, 14);

    $body = json_encode(reviewPayload(14));
    $sig = 'sha256='.hash_hmac('sha256', $body, 's3cret');
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'pull_request_review',
        'HTTP_X_HUB_SIGNATURE_256' => $sig,
        'HTTP_X_GITHUB_DELIVERY' => 'delivery-abc-123',
    ];

    test()->call(method: 'POST', uri: route('webhooks.github', $repo), server: $headers, content: $body)
        ->assertOk()
        ->assertJson(['dispatched' => true]);

    test()->call(method: 'POST', uri: route('webhooks.github', $repo), server: $headers, content: $body)
        ->assertOk()
        ->assertJson(['duplicate' => true]);

    expect(AgentRun::where('kind', AgentRunKind::RespondToReview->value)->count())->toBe(1);
    Queue::assertPushed(RespondToPrReviewJob::class, 1);
});

test('approved review with no body is ignored (no spurious dispatch)', function () {
    Queue::fake();
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'provider' => RepoProvider::Github,
        'webhook_secret' => 's3cret',
        'review_response_enabled' => true,
    ]);
    reviewSubtaskRun($repo, 15);

    postWebhook($repo, 's3cret', 'pull_request_review', [
        'action' => 'submitted',
        'review' => ['state' => 'approved', 'body' => ''],
        'pull_request' => ['number' => 15],
    ])->assertOk()->assertJson(['ignored' => true]);

    Queue::assertNothingPushed();
});

test('cascade gate ignores RespondToReview kind runs (ADR-0008)', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create(['provider' => RepoProvider::Github]);
    $origin = reviewSubtaskRun($repo, 16);

    // Subtask is already Done from the original Execute run.
    $subtask = Subtask::find($origin->runnable_id);
    $subtask->forceFill(['status' => TaskStatus::Done->value])->save();

    $reviewRun = AgentRun::create([
        'runnable_type' => $origin->runnable_type,
        'runnable_id' => $origin->runnable_id,
        'repo_id' => $repo->id,
        'kind' => AgentRunKind::RespondToReview->value,
        'status' => AgentRunStatus::Running->value,
    ]);

    // Marking the review-response run as failed must NOT flip the Subtask
    // back to Blocked or otherwise touch the cascade.
    app(ExecutionService::class)->markFailed($reviewRun, 'agent crashed');

    expect($subtask->fresh()->status->value)->toBe('done');
});
