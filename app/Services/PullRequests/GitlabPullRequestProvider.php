<?php

namespace App\Services\PullRequests;

use App\Models\Repo;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitlabPullRequestProvider implements PullRequestProvider
{
    public function createPullRequest(Repo $repo, string $head, string $base, string $title, ?string $body = null): array
    {
        if ($repo->access_token === null || $repo->access_token === '') {
            throw new RuntimeException('GitLab access_token is required to open merge requests.');
        }

        $project = $this->parseProjectPath($repo->url);
        $apiBase = rtrim((string) config('specify.gitlab.api_base', 'https://gitlab.com/api/v4'), '/');
        $encoded = rawurlencode($project);

        $response = Http::withHeaders([
            'PRIVATE-TOKEN' => $repo->access_token,
        ])->post("{$apiBase}/projects/{$encoded}/merge_requests", [
            'source_branch' => $head,
            'target_branch' => $base,
            'title' => $title,
            'description' => $body,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'GitLab MR creation failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        $data = $response->json();

        return [
            'url' => (string) ($data['web_url'] ?? ''),
            'number' => $data['iid'] ?? 0,
            'id' => $data['id'] ?? 0,
        ];
    }

    private function parseProjectPath(string $url): string
    {
        $url = preg_replace('/\.git$/', '', $url);

        if (preg_match('#^https?://[^/]+/(.+?)/?$#', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#^git@[^:]+:(.+?)/?$#', $url, $m)) {
            return $m[1];
        }

        throw new RuntimeException("Unable to parse project path from URL: {$url}");
    }
}
