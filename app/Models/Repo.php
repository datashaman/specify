<?php

namespace App\Models;

use App\Enums\RepoProvider;
use Database\Factories\RepoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

#[Fillable(['workspace_id', 'name', 'provider', 'url', 'default_branch', 'access_token', 'webhook_secret', 'metadata'])]
class Repo extends Model
{
    /** @use HasFactory<RepoFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => RepoProvider::class,
            'access_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('role', 'is_primary')
            ->withTimestamps();
    }

    /**
     * Owner/repo slug parsed from the URL, or null if it cannot be derived.
     */
    public function ownerRepo(): ?string
    {
        if ($this->provider !== RepoProvider::Github) {
            return null;
        }

        $path = parse_url($this->url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $slug = trim(preg_replace('/\.git$/', '', $path), '/');

        return preg_match('#^[^/]+/[^/]+$#', $slug) ? $slug : null;
    }

    /**
     * Latest commit on the default branch via GitHub API. 5-minute cache.
     *
     * @return array{sha:string, short:string, message:?string, html_url:?string}|null
     */
    public function latestCommit(): ?array
    {
        $slug = $this->ownerRepo();
        $token = $this->access_token;
        if ($slug === null || ! $token) {
            return null;
        }

        $branch = $this->default_branch ?: 'main';

        return Cache::remember(
            "repo:{$this->id}:commit:{$branch}",
            now()->addMinutes(5),
            function () use ($slug, $branch, $token) {
                $response = Http::withToken($token)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$slug}/commits/".urlencode($branch));

                if (! $response->ok()) {
                    return null;
                }

                $sha = (string) $response->json('sha');

                return [
                    'sha' => $sha,
                    'short' => substr($sha, 0, 7),
                    'message' => $response->json('commit.message'),
                    'html_url' => $response->json('html_url'),
                ];
            }
        );
    }
}
