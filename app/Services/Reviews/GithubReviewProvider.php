<?php

namespace App\Services\Reviews;

use App\Models\Repo;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts an advisory review on a GitHub pull request via the Reviews API
 * (`POST /repos/{owner}/{repo}/pulls/{pull_number}/reviews`).
 *
 * Always uses `event=COMMENT` — the human is still the gate. Line-attached
 * comments are sent inline; comments without a usable path/line collapse
 * into the review body so nothing is silently dropped.
 */
class GithubReviewProvider implements ReviewProvider
{
    /**
     * @param  list<ReviewComment>  $comments
     *
     * @throws RuntimeException When the repo has no access token, the URL is unparseable,
     *                          or the GitHub API returns a non-2xx response.
     */
    public function postReview(Repo $repo, int|string $pullRequestNumber, string $summary, array $comments): array
    {
        if ($repo->access_token === null || $repo->access_token === '') {
            throw new RuntimeException('GitHub access_token is required to post reviews.');
        }

        [$owner, $name] = $this->parseOwnerRepo($repo->url);
        $apiBase = rtrim((string) config('specify.github.api_base', 'https://api.github.com'), '/');

        [$inline, $bodyExtras] = $this->partitionComments($comments);

        $body = trim($summary);
        if ($bodyExtras !== []) {
            $body .= ($body === '' ? '' : "\n\n").implode("\n\n", $bodyExtras);
        }

        $payload = [
            'event' => 'COMMENT',
            'body' => $body,
        ];

        if ($inline !== []) {
            $payload['comments'] = $inline;
        }

        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer '.$repo->access_token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post("{$apiBase}/repos/{$owner}/{$name}/pulls/{$pullRequestNumber}/reviews", $payload);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'GitHub review post failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        $data = $response->json();

        return [
            'url' => isset($data['html_url']) ? (string) $data['html_url'] : null,
            'id' => $data['id'] ?? null,
        ];
    }

    /**
     * Split comments into (inline-attachable, body-extras-rendered-as-text).
     *
     * @param  list<ReviewComment>  $comments
     * @return array{0: list<array{path: string, line: int, body: string}>, 1: list<string>}
     */
    private function partitionComments(array $comments): array
    {
        $inline = [];
        $extras = [];

        foreach ($comments as $c) {
            $tag = '['.strtoupper($c->severity).'] ';
            if ($c->isLineAttached()) {
                $inline[] = [
                    'path' => (string) $c->path,
                    'line' => (int) $c->line,
                    'body' => $tag.$c->body,
                ];

                continue;
            }
            $location = $c->path !== null && $c->path !== '' ? ' (`'.$c->path.'`)' : '';
            $extras[] = '- '.$tag.$c->body.$location;
        }

        return [$inline, $extras];
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
