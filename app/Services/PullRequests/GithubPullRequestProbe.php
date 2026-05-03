<?php

namespace App\Services\PullRequests;

use App\Enums\RepoProvider;
use App\Models\Repo;
use App\Models\Story;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only GitHub API helpers for pull requests (mergeability, comments).
 *
 * All methods are best-effort: failures return null / false and log — callers
 * must not treat API errors as fatal (ADR-0004-style resilience on PR surfaces).
 */
final class GithubPullRequestProbe
{
    /**
     * Fetch mergeability for a pull request. Per-request memoization avoids
     * duplicate GETs when {@see Story::pullRequests()} walks siblings.
     *
     * @return array{mergeable: ?bool, mergeable_state: ?string}|null Null on error or unsupported repo.
     */
    public function probeMergeable(Repo $repo, int $number): ?array
    {
        if ($repo->provider !== RepoProvider::Github) {
            return null;
        }

        $token = $repo->access_token;
        if ($token === null || $token === '') {
            return null;
        }

        $slug = $repo->ownerRepo();
        if ($slug === null) {
            return null;
        }

        $memoKey = 'specify.github.mergeable.'.$repo->getKey().'.'.$number;
        $request = request();
        if ($request !== null && $request->attributes->has($memoKey)) {
            /** @var array{mergeable: ?bool, mergeable_state: ?string}|null $cached */
            $cached = $request->attributes->get($memoKey);

            return $cached;
        }

        $apiBase = rtrim((string) config('specify.github.api_base'), '/');

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("{$apiBase}/repos/{$slug}/pulls/{$number}");
        } catch (\Throwable $e) {
            Log::warning('specify.github.probe_mergeable.exception', [
                'repo_id' => $repo->getKey(),
                'number' => $number,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->ok()) {
            Log::warning('specify.github.probe_mergeable.http_error', [
                'repo_id' => $repo->getKey(),
                'number' => $number,
                'status' => $response->status(),
            ]);

            return null;
        }

        $mergeableJson = $response->json('mergeable');
        $state = $response->json('mergeable_state');

        $result = [
            'mergeable' => is_bool($mergeableJson) ? $mergeableJson : null,
            'mergeable_state' => is_string($state) ? $state : null,
        ];

        if ($request !== null) {
            $request->attributes->set($memoKey, $result);
        }

        return $result;
    }

    /**
     * Post a comment on a pull request (issues API). Best-effort.
     */
    public function postIssueComment(Repo $repo, int $pullRequestNumber, string $body): bool
    {
        if ($repo->provider !== RepoProvider::Github) {
            return false;
        }

        $token = $repo->access_token;
        if ($token === null || $token === '') {
            return false;
        }

        $slug = $repo->ownerRepo();
        if ($slug === null) {
            return false;
        }

        $apiBase = rtrim((string) config('specify.github.api_base'), '/');

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->post("{$apiBase}/repos/{$slug}/issues/{$pullRequestNumber}/comments", [
                    'body' => $body,
                ]);
        } catch (\Throwable $e) {
            Log::warning('specify.github.issue_comment.exception', [
                'repo_id' => $repo->getKey(),
                'number' => $pullRequestNumber,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('specify.github.issue_comment.http_error', [
                'repo_id' => $repo->getKey(),
                'number' => $pullRequestNumber,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
