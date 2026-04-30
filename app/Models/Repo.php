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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $check = $this->checkLatestCommit();

        return $check['commit'] ?? null;
    }

    /**
     * Human-readable reason the last commit check failed, or null on success.
     */
    public function latestCommitError(): ?string
    {
        $check = $this->checkLatestCommit();

        return $check['error'] ?? null;
    }

    /**
     * @return array{commit: ?array{sha:string, short:string, message:?string, html_url:?string}, error: ?string}
     */
    private function checkLatestCommit(): array
    {
        $token = $this->access_token;
        if (! $token) {
            return ['commit' => null, 'error' => __('No access token configured.')];
        }

        $slug = $this->ownerRepo();
        if ($slug === null) {
            return ['commit' => null, 'error' => __('Repo URL must be https://github.com/owner/repo[.git].')];
        }

        $branch = $this->default_branch ?: 'main';
        $key = "repo:{$this->id}:commit:{$branch}";

        if ($cached = Cache::get($key)) {
            return ['commit' => $cached, 'error' => null];
        }

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$slug}/commits/".urlencode($branch));

        if (! $response->ok()) {
            $status = $response->status();
            $message = (string) ($response->json('message') ?? $response->body());

            Log::warning('GitHub commit check failed', [
                'repo_id' => $this->id,
                'slug' => $slug,
                'branch' => $branch,
                'status' => $status,
                'body' => $message,
            ]);

            $error = match ($status) {
                401 => __('GitHub rejected the token (401 Unauthorized). It may be expired or revoked.'),
                403 => str_contains(strtolower($message), 'rate limit')
                    ? __('GitHub rate limit hit (403). Try again shortly.')
                    : __('GitHub denied access (403). Token may lack required scopes (repo) or SSO authorization for this org.'),
                404 => __(":slug or branch ':branch' not found (404). Check the slug, that the branch exists, and that the token has access to private repos.", ['slug' => $slug, 'branch' => $branch]),
                default => __('GitHub returned :status: :message', ['status' => $status, 'message' => $message]),
            };

            return ['commit' => null, 'error' => $error];
        }

        $sha = (string) $response->json('sha');
        $commit = [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'message' => $response->json('commit.message'),
            'html_url' => $response->json('html_url'),
        ];

        Cache::put($key, $commit, now()->addMinutes(5));

        return ['commit' => $commit, 'error' => null];
    }

    /**
     * Scopes the access token grants on github.com (from X-OAuth-Scopes).
     * Returns null when the request fails or the header is absent
     * (e.g. fine-grained PATs, which advertise permissions differently).
     *
     * @return ?array<int, string>
     */
    public function tokenScopes(): ?array
    {
        $token = $this->access_token;
        if ($this->provider !== RepoProvider::Github || ! $token) {
            return null;
        }

        return Cache::remember("repo:{$this->id}:token-scopes", now()->addMinutes(5), function () use ($token) {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/user');

            if (! $response->ok()) {
                return null;
            }

            $header = $response->header('X-OAuth-Scopes');
            if ($header === null || $header === '') {
                return null;
            }

            return array_values(array_filter(array_map('trim', explode(',', $header))));
        });
    }

    public function tokenHasScope(string $scope): bool
    {
        $scopes = $this->tokenScopes();

        return is_array($scopes) && in_array($scope, $scopes, true);
    }

    public function webhookUrl(): string
    {
        return route('webhooks.github', $this);
    }

    /**
     * Install (or update) a GitHub webhook for this repo using the access token.
     * Generates and stores a secret if missing. Idempotent — reuses an existing
     * hook with our URL if present.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function installGithubWebhook(): array
    {
        $token = $this->access_token;
        if ($this->provider !== RepoProvider::Github || ! $token) {
            return ['ok' => false, 'error' => __('Webhook auto-install requires a GitHub access token.')];
        }

        $slug = $this->ownerRepo();
        if ($slug === null) {
            return ['ok' => false, 'error' => __('Repo URL must be https://github.com/owner/repo[.git].')];
        }

        if (! $this->tokenHasScope('admin:repo_hook')) {
            return ['ok' => false, 'error' => __('Token is missing the admin:repo_hook scope.')];
        }

        $url = $this->webhookUrl();
        $secret = $this->webhook_secret ?: Str::random(40);

        $config = [
            'url' => $url,
            'content_type' => 'json',
            'secret' => $secret,
            'insecure_ssl' => '0',
        ];

        $http = Http::withToken($token)->withHeaders(['Accept' => 'application/vnd.github+json']);

        $existingHookId = data_get($this->metadata, 'github_hook_id');

        if (! $existingHookId) {
            $list = $http->get("https://api.github.com/repos/{$slug}/hooks");
            if ($list->ok()) {
                foreach ((array) $list->json() as $hook) {
                    if (data_get($hook, 'config.url') === $url) {
                        $existingHookId = data_get($hook, 'id');
                        break;
                    }
                }
            }
        }

        $payload = [
            'name' => 'web',
            'active' => true,
            'events' => ['pull_request'],
            'config' => $config,
        ];

        $response = $existingHookId
            ? $http->patch("https://api.github.com/repos/{$slug}/hooks/{$existingHookId}", $payload)
            : $http->post("https://api.github.com/repos/{$slug}/hooks", $payload);

        if (! $response->successful()) {
            $status = $response->status();
            $message = (string) ($response->json('message') ?? $response->body());

            Log::warning('GitHub webhook install failed', [
                'repo_id' => $this->id,
                'slug' => $slug,
                'status' => $status,
                'body' => $message,
                'mode' => $existingHookId ? 'update' : 'create',
            ]);

            return ['ok' => false, 'error' => __('GitHub returned :status: :message', ['status' => $status, 'message' => $message])];
        }

        $hookId = $existingHookId ?: data_get($response->json(), 'id');

        $metadata = (array) ($this->metadata ?? []);
        $metadata['github_hook_id'] = $hookId;
        $metadata['github_hook_url'] = $url;

        $this->forceFill([
            'webhook_secret' => $secret,
            'metadata' => $metadata,
        ])->save();

        return ['ok' => true, 'error' => null];
    }

    /**
     * Best-effort: delete the GitHub webhook associated with this repo.
     */
    public function deleteGithubWebhook(): void
    {
        $token = $this->access_token;
        $slug = $this->ownerRepo();
        $hookId = data_get($this->metadata, 'github_hook_id');

        if (! $token || ! $slug || ! $hookId) {
            return;
        }

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->delete("https://api.github.com/repos/{$slug}/hooks/{$hookId}");

        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('GitHub webhook delete failed', [
                'repo_id' => $this->id,
                'slug' => $slug,
                'hook_id' => $hookId,
                'status' => $response->status(),
                'body' => (string) ($response->json('message') ?? $response->body()),
            ]);
        }
    }
}
