<?php

namespace App\Services\PullRequests;

use App\Models\Repo;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** PR provider for Bitbucket Cloud repos via the v2.0 API. */
class BitbucketPullRequestProvider implements PullRequestProvider
{
    /**
     * @throws RuntimeException When the repo has no access token, the URL is unparseable,
     *                          or the Bitbucket API returns a non-2xx response.
     */
    public function createPullRequest(Repo $repo, string $head, string $base, string $title, ?string $body = null): array
    {
        if ($repo->access_token === null || $repo->access_token === '') {
            throw new RuntimeException('Bitbucket access_token is required to open pull requests.');
        }

        [$workspace, $slug] = $this->parseWorkspaceSlug($repo->url);
        $apiBase = rtrim((string) config('specify.bitbucket.api_base', 'https://api.bitbucket.org/2.0'), '/');

        $response = Http::withToken($repo->access_token)
            ->post("{$apiBase}/repositories/{$workspace}/{$slug}/pullrequests", [
                'title' => $title,
                'description' => $body,
                'source' => ['branch' => ['name' => $head]],
                'destination' => ['branch' => ['name' => $base]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Bitbucket PR creation failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        $data = $response->json();

        return [
            'url' => (string) ($data['links']['html']['href'] ?? ''),
            'number' => $data['id'] ?? 0,
            'id' => $data['id'] ?? 0,
        ];
    }

    public function findOpenPullRequest(Repo $repo, string $head): ?array
    {
        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseWorkspaceSlug(string $url): array
    {
        $url = preg_replace('/\.git$/', '', $url);

        if (preg_match('#^https?://[^/]+/([^/]+)/([^/]+)/?$#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        if (preg_match('#^git@[^:]+:([^/]+)/([^/]+)/?$#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        throw new RuntimeException("Unable to parse workspace/repo from URL: {$url}");
    }
}
