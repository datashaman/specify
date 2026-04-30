<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class GithubRepoCatalog
{
    /**
     * Repos the user can access on github.com (owner / collaborator / org member).
     * Cached 5 minutes per user — same key the Repos page uses.
     *
     * @return array<int, array{full_name:string, name:string, html_url:string, default_branch:string, private:bool}>
     */
    public static function forUser(User $user): array
    {
        $token = $user->github_token;
        if (! $token) {
            return [];
        }

        return Cache::remember("user:{$user->id}:github-repos", now()->addMinutes(5), function () use ($token) {
            $repos = [];
            for ($page = 1; $page <= 5; $page++) {
                $response = Http::withToken($token)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get('https://api.github.com/user/repos', [
                        'per_page' => 100,
                        'page' => $page,
                        'sort' => 'pushed',
                        'affiliation' => 'owner,collaborator,organization_member',
                    ]);

                if (! $response->ok()) {
                    break;
                }

                $batch = (array) $response->json();
                if (empty($batch)) {
                    break;
                }

                foreach ($batch as $r) {
                    $repos[] = [
                        'full_name' => (string) data_get($r, 'full_name'),
                        'name' => (string) data_get($r, 'name'),
                        'html_url' => (string) data_get($r, 'html_url'),
                        'default_branch' => (string) (data_get($r, 'default_branch') ?: 'main'),
                        'private' => (bool) data_get($r, 'private'),
                    ];
                }

                if (count($batch) < 100) {
                    break;
                }
            }

            return $repos;
        });
    }

    /**
     * @return array{full_name:string, name:string, html_url:string, default_branch:string, private:bool}|null
     */
    public static function findByFullName(User $user, string $fullName): ?array
    {
        foreach (self::forUser($user) as $repo) {
            if ($repo['full_name'] === $fullName) {
                return $repo;
            }
        }

        return null;
    }
}
