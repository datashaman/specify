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

    public function findOpenPullRequest(Repo $repo, string $head): ?array
    {
        if ($repo->access_token === null || $repo->access_token === '') {
            return null;
        }

        [$owner, $name] = $this->parseOwnerRepo($repo->url);
        $apiBase = rtrim((string) config('specify.github.api_base', 'https://api.github.com'), '/');
        $headRepo = $owner.'/'.$name;

        // GitHub's `head=user:branch` query-param filter is brittle:
        // it's documented for fork PRs and silently returns empty for
        // same-repo PRs whose head ref contains slashes (which all our
        // `specify/<feature>/<story>` branches do). Smoke testing on a
        // real repo confirmed the filtered request returned [] while the
        // PR was open. The robust fix is to list open PRs and match the
        // head.ref client-side, paginating via the `Link: rel="next"`
        // header. Cap at MAX_PAGES * 100 PRs so a runaway queue can't
        // burn the API budget.
        $url = "{$apiBase}/repos/{$owner}/{$name}/pulls?state=open&per_page=100";
        $maxPages = (int) config('specify.github.find_pr_max_pages', 10);

        for ($page = 0; $page < $maxPages; $page++) {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer '.$repo->access_token,
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->get($url);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data) || $data === []) {
                return null;
            }

            foreach ($data as $pr) {
                if (! is_array($pr)) {
                    continue;
                }
                if (($pr['head']['ref'] ?? null) !== $head) {
                    continue;
                }
                // Cross-repo guard: the open-PR list includes fork PRs
                // whose head.ref can collide with a same-repo branch.
                // Only adopt PRs whose head is on the destination repo
                // — `createPullRequest` always sends `head` as a bare
                // branch name, so the PR we want is always same-repo.
                if (($pr['head']['repo']['full_name'] ?? null) !== $headRepo) {
                    continue;
                }

                return [
                    'url' => (string) ($pr['html_url'] ?? ''),
                    'number' => $pr['number'] ?? 0,
                    'id' => $pr['id'] ?? 0,
                ];
            }

            $next = $this->nextPageUrl($response->header('Link'));
            if ($next === null) {
                return null;
            }
            $url = $next;
        }

        return null;
    }

    /**
     * Parse the next-page URL out of a GitHub `Link` header
     * (`<https://...?page=2>; rel="next", <...>; rel="last"`).
     */
    private function nextPageUrl(?string $linkHeader): ?string
    {
        if ($linkHeader === null || $linkHeader === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $m)) {
            return $m[1];
        }

        return null;
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
