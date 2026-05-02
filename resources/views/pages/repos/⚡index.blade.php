<?php

use App\Enums\RepoProvider;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Repo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Repositories')] class extends Component {
    public int $project_id;

    public string $githubRepoSearch = '';

    public function mount(int $project): void
    {
        $user = Auth::user();
        abort_unless(in_array((int) $project, $user->accessibleProjectIds(), true), 404);
        $this->project_id = (int) $project;
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function project(): ?Project
    {
        return Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->with('team')
            ->find($this->project_id);
    }

    #[Computed]
    public function canManage(): bool
    {
        $user = Auth::user();
        if ($this->project) {
            return $user->canApproveInProject($this->project);
        }
        $teamId = $user->current_team_id;
        if (! $teamId) {
            return false;
        }

        return in_array($user->roleInTeam($teamId), [TeamRole::Owner, TeamRole::Admin], true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Repo>
     */
    public function repos()
    {
        if (! $this->project) {
            return collect();
        }

        return $this->project->repos()->orderBy('name')->get();
    }

    public function setPrimary(int $repoId): void
    {
        abort_unless($this->canManage, 403);
        abort_unless($this->project, 422);

        $repo = $this->project->repos()->find($repoId);
        abort_unless($repo, 404);

        $this->project->setPrimaryRepo($repo);
    }

    public function remove(int $repoId): void
    {
        abort_unless($this->canManage, 403);
        abort_unless($this->project, 422);

        $repo = $this->project->repos()->find($repoId);
        abort_unless($repo, 404);

        if ($repo->provider === RepoProvider::Github) {
            $repo->deleteGithubWebhook();
        }

        $repo->projects()->detach();
        $repo->delete();
    }

    public function installWebhook(int $repoId): void
    {
        abort_unless($this->canManage, 403);
        abort_unless($this->project, 422);

        $repo = $this->project->repos()->find($repoId);
        abort_unless($repo, 404);

        $result = $repo->installGithubWebhook();
        if (! $result['ok']) {
            $this->addError('repos', __('Webhook install failed: :error', ['error' => $result['error']]));
        }
    }

    /**
     * Repos the authenticated user can access on github.com. Cached 5 minutes.
     *
     * @return array<int, array{full_name:string, name:string, html_url:string, default_branch:string, private:bool}>
     */
    public function githubRepos(): array
    {
        $user = Auth::user();
        $token = $user?->github_token;
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
     * Filtered GitHub repo options, excluding ones already attached to this project.
     *
     * @return array<int, array{full_name:string, name:string, html_url:string, default_branch:string, private:bool}>
     */
    public function githubRepoOptions(): array
    {
        $existingSlugs = $this->repos()
            ->map(fn ($r) => trim(preg_replace('/\.git$/', '', (string) parse_url($r->url, PHP_URL_PATH)), '/'))
            ->filter()
            ->all();

        $needle = strtolower(trim($this->githubRepoSearch));

        return collect($this->githubRepos())
            ->reject(fn ($r) => in_array($r['full_name'], $existingSlugs, true))
            ->when($needle !== '', fn ($c) => $c->filter(fn ($r) => str_contains(strtolower($r['full_name']), $needle)))
            ->take(10)
            ->values()
            ->all();
    }

    public function addGithubRepo(string $fullName): void
    {
        abort_unless($this->canManage, 403);
        abort_unless($this->project, 422);

        $token = Auth::user()->github_token;
        abort_unless($token, 422);

        $match = collect($this->githubRepos())->firstWhere('full_name', $fullName);
        abort_unless($match, 404);

        $workspaceId = $this->project->team?->workspace_id;
        abort_unless($workspaceId, 422);

        $url = $match['html_url'].'.git';

        $repo = Repo::query()->where('workspace_id', $workspaceId)->where('url', $url)->first()
            ?? Repo::create([
                'workspace_id' => $workspaceId,
                'name' => $match['name'],
                'provider' => RepoProvider::Github,
                'url' => $url,
                'default_branch' => $match['default_branch'],
                'access_token' => $token,
            ]);

        if (! $repo->webhook_secret) {
            $result = $repo->installGithubWebhook();
            if (! $result['ok'] && $result['error'] !== null && ! str_contains($result['error'], 'admin:repo_hook')) {
                $this->addError('repos', __('Webhook auto-install failed: :error', ['error' => $result['error']]));
            }
        }

        $this->project->attachRepo($repo, role: null, primary: false);

        $this->reset(['githubRepoSearch']);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Repos') }}</flux:heading>

    @if (! $this->project)
        <flux:text class="text-sm text-zinc-500">{{ __('Select a project to manage its repositories.') }}</flux:text>
    @else
        <flux:text class="text-sm text-zinc-500">{{ __('Repositories for :project.', ['project' => $this->project->name]) }}</flux:text>

        @error('repos')
            <flux:text class="text-red-600">{{ $message }}</flux:text>
        @enderror

        @php($projectRepos = $this->repos())
        @php($multipleRepos = $projectRepos->count() > 1)
        <section class="flex flex-col gap-3">
            @forelse ($projectRepos as $repo)
                @php($commit = $repo->latestCommit())
                @php($isPrimary = (bool) ($repo->pivot->is_primary ?? false))
                <flux:card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge variant="solid">{{ $repo->name }}</flux:badge>
                                @if ($multipleRepos && $isPrimary)
                                    <flux:badge color="green">{{ __('primary') }}</flux:badge>
                                @endif
                                @if ($repo->webhook_secret)
                                    <flux:badge color="green">{{ __('webhook installed') }}</flux:badge>
                                @else
                                    <flux:badge>{{ __('no webhook') }}</flux:badge>
                                @endif
                                @if ($commit)
                                    @if ($commit['html_url'] ?? null)
                                        <a href="{{ $commit['html_url'] }}" target="_blank" rel="noopener">
                                            <flux:badge color="green">{{ $repo->default_branch }}{{ '@'.$commit['short'] }}</flux:badge>
                                        </a>
                                    @else
                                        <flux:badge color="green">{{ $repo->default_branch }}{{ '@'.$commit['short'] }}</flux:badge>
                                    @endif
                                @elseif ($repo->access_token && $repo->provider === RepoProvider::Github)
                                    <flux:tooltip :content="$repo->latestCommitError() ?? __('token check failed')">
                                        <flux:badge color="red">{{ __('token check failed') }}</flux:badge>
                                    </flux:tooltip>
                                @endif
                            </div>
                            @php($homeUrl = preg_replace('/\.git$/', '', $repo->url))
                            <a href="{{ $homeUrl }}" target="_blank" rel="noopener" class="text-sm text-zinc-500 hover:underline">{{ $homeUrl }}</a>
                        </div>
                        @if ($this->canManage)
                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                @if ($multipleRepos && ! $isPrimary)
                                    <flux:button wire:click="setPrimary({{ $repo->id }})">{{ __('Make primary') }}</flux:button>
                                @endif
                                @if (! $repo->webhook_secret && $repo->provider === RepoProvider::Github && $repo->access_token)
                                    <flux:button wire:click="installWebhook({{ $repo->id }})">{{ __('Install webhook') }}</flux:button>
                                @endif
                                <flux:button
                                    variant="danger"
                                    wire:click="remove({{ $repo->id }})"
                                    wire:confirm="{{ __('Remove :name from this project?', ['name' => $repo->name]) }}"
                                >
                                    {{ __('Remove') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No repositories yet. Add one below.') }}</flux:text>
            @endforelse
        </section>

        @if ($this->canManage)
            <section class="flex flex-col gap-3">
                <flux:heading size="lg">{{ __('Add a repository') }}</flux:heading>

                @if (auth()->user()->github_token)
                    @php($options = $this->githubRepoOptions())
                    <div class="flex flex-col gap-2">
                        <flux:input
                            wire:model.live.debounce.200ms="githubRepoSearch"
                            icon="magnifying-glass"
                            :placeholder="__('Search your GitHub repositories…')"
                        />
                        <div class="flex flex-col gap-1">
                            @forelse ($options as $option)
                                <button
                                    type="button"
                                    wire:click="addGithubRepo('{{ $option['full_name'] }}')"
                                    class="flex cursor-pointer items-center justify-between gap-3 rounded-md border border-zinc-200 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                >
                                    <span class="flex items-center gap-2">
                                        <flux:icon name="folder" class="size-4 text-zinc-500" />
                                        <span>{{ $option['full_name'] }}</span>
                                        @if ($option['private'])
                                            <flux:badge size="sm">{{ __('private') }}</flux:badge>
                                        @endif
                                    </span>
                                    <flux:text class="text-xs text-zinc-500">{{ $option['default_branch'] }}</flux:text>
                                </button>
                            @empty
                                <flux:text class="text-sm text-zinc-500">
                                    {{ $githubRepoSearch !== '' ? __('No matching repositories.') : __('All your GitHub repositories are already added.') }}
                                </flux:text>
                            @endforelse
                        </div>
                    </div>
                @else
                    <flux:text class="text-sm text-zinc-500">
                        {{ __('Connect your GitHub account from Settings to add repositories.') }}
                    </flux:text>
                @endif
            </section>
        @endif
    @endif
</div>
