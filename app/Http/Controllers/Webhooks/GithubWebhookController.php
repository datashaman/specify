<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\Repo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, Repo $repo): JsonResponse
    {
        $secret = $repo->webhook_secret;
        $signature = $request->header('X-Hub-Signature-256', '');

        if ($secret === null || $secret === '' || ! $this->signatureValid($request->getContent(), $signature, $secret)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        if ($request->header('X-GitHub-Event') !== 'pull_request') {
            return response()->json(['ignored' => true]);
        }

        $payload = $request->json()->all();
        $action = $payload['action'] ?? null;
        $number = $payload['pull_request']['number'] ?? null;

        if ($number === null) {
            return response()->json(['ignored' => true]);
        }

        $run = AgentRun::query()
            ->where('repo_id', $repo->getKey())
            ->whereJsonContains('output->pull_request_number', (int) $number)
            ->latest('id')
            ->first();

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

        return response()->json(['matched' => true, 'run_id' => $run->getKey()]);
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
