<?php

namespace App\Services\Reviews;

use App\Models\Repo;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches review comments and the latest review summary for a Pull Request
 * so the `ReviewResponder` agent has the same context a human reviewer
 * would have when triaging the feedback (ADR-0008).
 *
 * GitHub-only for now — Bitbucket / GitLab can grow their own fetcher and
 * register against a multi-provider seam if/when needed.
 */
class ReviewCommentsFetcher
{
    /**
     * @return array{0: string, 1: list<array{path: ?string, line: ?int, body: string, author: ?string}>}
     *                                                                                                    [reviewSummaryBody, inlineComments]
     */
    public function fetch(Repo $repo, int $pullRequestNumber): array
    {
        if ($repo->access_token === null || $repo->access_token === '') {
            throw new RuntimeException('GitHub access_token is required to fetch review comments.');
        }

        [$owner, $name] = $this->parseOwnerRepo($repo->url);
        $apiBase = rtrim((string) config('specify.github.api_base', 'https://api.github.com'), '/');

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer '.$repo->access_token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $reviewSummary = '';
        $reviewsResponse = Http::withHeaders($headers)->get(
            "{$apiBase}/repos/{$owner}/{$name}/pulls/{$pullRequestNumber}/reviews"
        );
        if ($reviewsResponse->successful()) {
            $reviews = (array) $reviewsResponse->json();
            // Most-recent review with a non-empty body wins.
            usort($reviews, fn ($a, $b) => strcmp((string) ($b['submitted_at'] ?? ''), (string) ($a['submitted_at'] ?? '')));
            foreach ($reviews as $review) {
                $body = trim((string) ($review['body'] ?? ''));
                if ($body !== '') {
                    $reviewSummary = $body;
                    break;
                }
            }
        }

        $comments = [];
        $commentsResponse = Http::withHeaders($headers)->get(
            "{$apiBase}/repos/{$owner}/{$name}/pulls/{$pullRequestNumber}/comments",
            ['per_page' => 100],
        );
        if ($commentsResponse->successful()) {
            foreach ((array) $commentsResponse->json() as $c) {
                $comments[] = [
                    'path' => isset($c['path']) ? (string) $c['path'] : null,
                    'line' => isset($c['line']) ? (int) $c['line'] : (isset($c['original_line']) ? (int) $c['original_line'] : null),
                    'body' => (string) ($c['body'] ?? ''),
                    'author' => isset($c['user']['login']) ? (string) $c['user']['login'] : null,
                ];
            }
        }

        return [$reviewSummary, $comments];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseOwnerRepo(string $url): array
    {
        $url = preg_replace('/\.git$/', '', $url);

        if (preg_match('#^https?://[^/]+/([^/]+)/([^/]+)/?$#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        if (preg_match('#^git@[^:]+:([^/]+)/([^/]+)/?$#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        throw new RuntimeException("Unable to parse owner/repo from URL: {$url}");
    }
}
