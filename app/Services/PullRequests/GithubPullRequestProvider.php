<?php

namespace App\Services\PullRequests;

use App\Models\Repo;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** PR provider for GitHub repos via the REST API (`POST /repos/{owner}/{repo}/pulls`). */
class GithubPullRequestProvider implements PullRequestProvider
{
    /**
     * @throws RuntimeException When the repo has no access token, the URL is unparseable,
     *                          or the GitHub API returns a non-2xx response.
     */
    public function createPullRequest(Repo $repo, string $head, string $base, string $title, ?string $body = null): array
    {
        if ($repo->access_token === null || $repo->access_token === '') {
            throw new RuntimeException('GitHub access_token is required to open pull requests.');
        }

        [$owner, $name] = $this->parseOwnerRepo($repo->url);
        $apiBase = rtrim((string) config('specify.github.api_base', 'https://api.github.com'), '/');

        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer '.$repo->access_token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post("{$apiBase}/repos/{$owner}/{$name}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'GitHub PR creation failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        $data = $response->json();

        return [
            'url' => (string) ($data['html_url'] ?? ''),
            'number' => $data['number'] ?? 0,
            'id' => $data['id'] ?? 0,
        ];
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
