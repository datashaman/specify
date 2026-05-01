<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RespondToPrReviewJob;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives GitHub webhooks for a `Repo` and reacts to `pull_request*`
 * events. Two paths today:
 *
 *   - `pull_request` — stamp lifecycle state (opened/closed/merged) onto the
 *     originating AgentRun's `output`.
 *   - `pull_request_review` / `pull_request_review_comment` — when the repo
 *     opted into automatic review responses, dispatch a `RespondToPrReviewJob`
 *     that creates a new `RespondToReview` AgentRun and pushes a fix on the
 *     originating Subtask's branch (ADR-0008).
 *
 * Idempotency is enforced via the `webhook_events.delivery_id` unique index:
 * a duplicate `X-GitHub-Delivery` is acked once and ignored on retry.
 */
class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, Repo $repo): JsonResponse
    {
        $secret = $repo->webhook_secret;
        $signature = $request->header('X-Hub-Signature-256', '');
        $signatureValid = $secret !== null && $secret !== ''
            && $this->signatureValid($request->getContent(), $signature, $secret);

        $event = $request->header('X-GitHub-Event', 'unknown');
        $deliveryId = $request->header('X-GitHub-Delivery') ?: null;
        $payload = $request->json()->all();
        $action = $payload['action'] ?? null;

        if ($deliveryId !== null) {
            $existing = WebhookEvent::where('delivery_id', $deliveryId)->first();
            if ($existing !== null) {
                return response()->json(['duplicate' => true, 'event_id' => $existing->getKey()]);
            }
        }

        $log = WebhookEvent::create([
            'repo_id' => $repo->getKey(),
            'provider' => 'github',
            'event' => $event,
            'action' => $action,
            'delivery_id' => $deliveryId,
            'signature_valid' => $signatureValid,
            'payload' => $payload,
        ]);

        if (! $signatureValid) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        return match ($event) {
            'pull_request' => $this->handlePullRequest($repo, $payload, $action, $log),
            'pull_request_review' => $this->handleReview($repo, $payload, $action, $log),
            'pull_request_review_comment' => $this->handleReviewComment($repo, $payload, $action, $log),
            default => response()->json(['ignored' => true]),
        };
    }

    private function handlePullRequest(Repo $repo, array $payload, ?string $action, WebhookEvent $log): JsonResponse
    {
        $number = $payload['pull_request']['number'] ?? null;
        if ($number === null) {
            return response()->json(['ignored' => true]);
        }

        $run = $this->originatingRun($repo, (int) $number);
        if ($run === null) {
            return response()->json(['matched' => false]);
        }

        $output = $run->output ?? [];
        $output['pull_request_action'] = $action;

        if ($action === 'closed') {
            $output['pull_request_merged'] = (bool) ($payload['pull_request']['merged'] ?? false);
            $output['pull_request_closed_at'] = $payload['pull_request']['closed_at'] ?? null;
            $output['pull_request_merged_at'] = $payload['pull_request']['merged_at'] ?? null;
        }

        $run->forceFill(['output' => $output])->save();
        $log->forceFill(['matched_run_id' => $run->getKey()])->save();

        return response()->json(['matched' => true, 'run_id' => $run->getKey()]);
    }

    private function handleReview(Repo $repo, array $payload, ?string $action, WebhookEvent $log): JsonResponse
    {
        if ($action !== 'submitted') {
            return response()->json(['ignored' => true]);
        }

        $review = $payload['review'] ?? [];
        $hasComments = trim((string) ($review['body'] ?? '')) !== ''
            || ! in_array($review['state'] ?? '', ['approved'], true);
        if (! $hasComments) {
            return response()->json(['ignored' => true]);
        }

        return $this->dispatchReviewResponse($repo, $payload, $log);
    }

    private function handleReviewComment(Repo $repo, array $payload, ?string $action, WebhookEvent $log): JsonResponse
    {
        if ($action !== 'created') {
            return response()->json(['ignored' => true]);
        }

        return $this->dispatchReviewResponse($repo, $payload, $log);
    }

    private function dispatchReviewResponse(Repo $repo, array $payload, WebhookEvent $log): JsonResponse
    {
        $number = $payload['pull_request']['number'] ?? null;
        if ($number === null) {
            return response()->json(['ignored' => true]);
        }

        $run = $this->originatingRun($repo, (int) $number);
        if ($run === null) {
            return response()->json(['matched' => false]);
        }

        $log->forceFill(['matched_run_id' => $run->getKey()])->save();

        if (! $repo->review_response_enabled) {
            return response()->json(['matched' => true, 'dispatched' => false, 'reason' => 'review_response_disabled']);
        }

        $cycles = AgentRun::query()
            ->where('repo_id', $repo->getKey())
            ->where('kind', AgentRunKind::RespondToReview->value)
            ->whereJsonContains('output->pull_request_number', (int) $number)
            ->count();

        if ($cycles >= ($repo->max_review_response_cycles ?? 3)) {
            return response()->json([
                'matched' => true,
                'dispatched' => false,
                'reason' => 'max_cycles_reached',
                'cycles' => $cycles,
            ]);
        }

        $newRun = AgentRun::create([
            'runnable_type' => $run->runnable_type,
            'runnable_id' => $run->runnable_id,
            'repo_id' => $repo->getKey(),
            'working_branch' => $run->working_branch,
            'executor_driver' => $run->executor_driver,
            'kind' => AgentRunKind::RespondToReview->value,
            'status' => AgentRunStatus::Queued->value,
            'output' => ['pull_request_number' => (int) $number, 'origin_run_id' => $run->getKey()],
        ]);

        RespondToPrReviewJob::dispatch($newRun->getKey());

        return response()->json([
            'matched' => true,
            'dispatched' => true,
            'run_id' => $newRun->getKey(),
            'cycle' => $cycles + 1,
        ]);
    }

    private function originatingRun(Repo $repo, int $number): ?AgentRun
    {
        return AgentRun::query()
            ->where('repo_id', $repo->getKey())
            ->where('kind', AgentRunKind::Execute->value)
            ->whereJsonContains('output->pull_request_number', $number)
            ->latest('id')
            ->first();
    }

    private function signatureValid(string $body, string $header, string $secret): bool
    {
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, $header);
    }
}
