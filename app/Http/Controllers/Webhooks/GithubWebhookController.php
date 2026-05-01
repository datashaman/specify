<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AgentRunKind;
use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\WebhookEvent;
use App\Services\ExecutionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives GitHub webhooks for a `Repo` and reacts to `pull_request*`
 * events. Two paths today:
 *
 *   - `pull_request` — stamp lifecycle state (opened/closed/merged) onto the
 *     originating AgentRun's `output`.
 *   - `pull_request_review` / `pull_request_review_comment` — when the repo
 *     opted into automatic review responses, ask `ExecutionService` to
 *     dispatch a RespondToReview run that pushes a fix on the originating
 *     Subtask's branch (ADR-0008). All AgentRun creation / cycle-cap /
 *     race-safety lives in `ExecutionService::dispatchReviewResponse`.
 *
 * Order of operations: signature validation runs FIRST. Idempotency on
 * `X-GitHub-Delivery` only applies to signature-valid requests, so an
 * unauthenticated caller cannot pre-poison or replay a delivery_id.
 *
 * Concurrent identical deliveries are de-duped via the
 * `webhook_events.delivery_id` unique index — the first request lands the
 * row; the second hits a unique-constraint violation that we catch and
 * convert into a `{duplicate: true}` response.
 */
class GithubWebhookController extends Controller
{
    public function __construct(public ExecutionService $execution) {}

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

        if (! $signatureValid) {
            // Persist invalid-signature attempts for audit, but with a NULL
            // delivery_id so an attacker cannot occupy the unique slot for a
            // legitimate later delivery with the same X-GitHub-Delivery.
            WebhookEvent::create([
                'repo_id' => $repo->getKey(),
                'provider' => 'github',
                'event' => $event,
                'action' => $action,
                'delivery_id' => null,
                'signature_valid' => false,
                'payload' => $payload,
            ]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        try {
            $log = WebhookEvent::create([
                'repo_id' => $repo->getKey(),
                'provider' => 'github',
                'event' => $event,
                'action' => $action,
                'delivery_id' => $deliveryId,
                'signature_valid' => true,
                'payload' => $payload,
            ]);
        } catch (QueryException $e) {
            // Race / retry: the unique index on delivery_id rejected this
            // insert because a sibling request just landed the same delivery.
            if ($deliveryId !== null && $this->isUniqueConstraintViolation($e)) {
                $existing = WebhookEvent::where('delivery_id', $deliveryId)->first();

                return response()->json(['duplicate' => true, 'event_id' => $existing?->getKey()]);
            }

            throw $e;
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

        $origin = $this->originatingRun($repo, (int) $number);
        if ($origin === null) {
            return response()->json(['matched' => false]);
        }

        $log->forceFill(['matched_run_id' => $origin->getKey()])->save();

        $result = $this->execution->dispatchReviewResponse($repo, $origin, (int) $number);

        return match ($result['status']) {
            'dispatched' => response()->json([
                'matched' => true,
                'dispatched' => true,
                'run_id' => $result['run']->getKey(),
                'cycle' => $result['cycle'],
            ]),
            'review_response_disabled' => response()->json([
                'matched' => true,
                'dispatched' => false,
                'reason' => 'review_response_disabled',
            ]),
            'max_cycles_reached' => response()->json([
                'matched' => true,
                'dispatched' => false,
                'reason' => 'max_cycles_reached',
                'cycles' => $result['cycles'],
            ]),
        };
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

    /**
     * SQLite/MySQL/Postgres all surface unique-constraint violations slightly
     * differently — match on the SQLSTATE code (23000 / 23505) and on the
     * driver-specific message hint. Any of these signals is enough to treat
     * the failure as a benign duplicate-delivery race.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        if (in_array($code, ['23000', '23505'], true)) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key value');
    }
}
