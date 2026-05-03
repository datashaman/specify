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
     * GitHub repos matching the current search needle. Uses the GitHub search
     * API so we never download every repo the user has access to. Empty
     * needle returns nothing — show a hint instead. Cached 60s per (user,
     * needle) so debounced typing doesn't burn rate-limit.
     *
     * @return array<int, array{full_name:string, name:string, html_url:string, default_branch:string, private:bool}>
     */
    public function githubRepoOptions(): array
    {
        $user = Auth::user();
        $token = $user?->github_token;
        $needle = trim($this->githubRepoSearch);
        if (! $token || $needle === '' || mb_strlen($needle) < 2) {
            return [];
        }

        $existingSlugs = $this->repos()
            ->map(fn ($r) => trim(preg_replace('/\.git$/', '', (string) parse_url($r->url, PHP_URL_PATH)), '/'))
            ->filter()
            ->all();

        $cacheKey = "user:{$user->id}:github-repo-search:".sha1(mb_strtolower($needle));
        $matches = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($token, $needle) {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/search/repositories', [
                    'q' => $needle.' user:@me fork:true',
                    'per_page' => 20,
                    'sort' => 'updated',
                ]);

            if (! $response->ok()) {
                return [];
            }

            return collect((array) data_get($response->json(), 'items', []))
                ->map(fn ($r) => [
                    'full_name' => (string) data_get($r, 'full_name'),
                    'name' => (string) data_get($r, 'name'),
                    'html_url' => (string) data_get($r, 'html_url'),
                    'default_branch' => (string) (data_get($r, 'default_branch') ?: 'main'),
                    'private' => (bool) data_get($r, 'private'),
                ])
                ->all();
        });

        return collect($matches)
            ->reject(fn ($r) => in_array($r['full_name'], $existingSlugs, true))
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

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get('https://api.github.com/repos/'.$fullName);
        abort_unless($response->ok(), 404);
        $payload = (array) $response->json();

        $match = [
            'full_name' => (string) data_get($payload, 'full_name'),
            'name' => (string) data_get($payload, 'name'),
            'html_url' => (string) data_get($payload, 'html_url'),
            'default_branch' => (string) (data_get($payload, 'default_branch') ?: 'main'),
        ];
        abort_unless($match['html_url'] !== '' && $match['name'] !== '', 404);

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

    public ?int $confirmingRemoveId = null;

    public function confirmRemove(int $repoId): void
    {
        $this->confirmingRemoveId = $repoId;
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemoveId = null;
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <div class="flex items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Repos') }}</flux:heading>
        @if ($this->project && $this->canManage)
            <flux:modal.trigger name="add-repo-modal">
                <flux:button variant="primary">{{ __('+ Add repository') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

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
                <x-repo.summary-card
                    :repo="$repo"
                    :multiple-repos="$multipleRepos"
                    :is-primary="$isPrimary"
                    :commit="$commit"
                    :can-manage="$this->canManage"
                />
            @empty
                <flux:text class="text-zinc-500">{{ __('No repositories yet.') }}</flux:text>
            @endforelse
        </section>

        @if ($this->canManage)
            @php($confirmingRepo = $confirmingRemoveId ? $projectRepos->firstWhere('id', $confirmingRemoveId) : null)
            <flux:modal name="remove-repo-modal" class="md:w-96" @close="$wire.cancelRemove()">
                <div class="flex flex-col gap-4">
                    <flux:heading size="lg">{{ __('Remove repository?') }}</flux:heading>
                    <flux:text>
                        @if ($confirmingRepo)
                            {{ __('Detach :name from :project. The repository remains in the workspace if attached to other projects.', ['name' => $confirmingRepo->name, 'project' => $this->project->name]) }}
                        @else
                            {{ __('Detach this repository from the project.') }}
                        @endif
                    </flux:text>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" wire:click="cancelRemove">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:modal.close>
                            <flux:button variant="danger" icon="trash" wire:click="remove({{ $confirmingRemoveId ?? 0 }})">{{ __('Remove') }}</flux:button>
                        </flux:modal.close>
                    </div>
                </div>
            </flux:modal>

            <flux:modal name="add-repo-modal" class="md:w-[32rem]">
                <div class="flex flex-col gap-4">
                    <flux:heading size="lg">{{ __('Add a repository') }}</flux:heading>

                    @if (auth()->user()->github_token)
                        @php($options = $this->githubRepoOptions())
                        <flux:input
                            wire:model.live.debounce.200ms="githubRepoSearch"
                            icon="magnifying-glass"
                            :placeholder="__('Search your GitHub repositories…')"
                        />
                        <div class="flex max-h-72 flex-col gap-1 overflow-y-auto" wire:loading.class="opacity-60" wire:target="githubRepoSearch,addGithubRepo">
                            @forelse ($options as $option)
                                <button
                                    type="button"
                                    wire:click="addGithubRepo('{{ $option['full_name'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="addGithubRepo"
                                    class="flex cursor-pointer items-center justify-between gap-3 rounded-md border border-zinc-200 px-3 py-2 text-left text-sm hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                >
                                    <span class="flex items-center gap-2">
                                        <flux:icon name="folder" class="size-4 text-zinc-500" />
                                        <span>{{ $option['full_name'] }}</span>
                                        @if ($option['private'])
                                            <flux:badge size="sm">{{ __('private') }}</flux:badge>
                                        @endif
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <flux:text class="text-xs text-zinc-500">{{ $option['default_branch'] }}</flux:text>
                                        <flux:icon
                                            name="arrow-path"
                                            class="size-4 hidden text-zinc-400 motion-safe:animate-spin"
                                            wire:loading.class.remove="hidden"
                                            wire:target="addGithubRepo('{{ $option['full_name'] }}')"
                                        />
                                    </span>
                                </button>
                            @empty
                                <flux:text class="text-sm text-zinc-500">
                                    {{ $githubRepoSearch === '' || mb_strlen(trim($githubRepoSearch)) < 2
                                        ? __('Type at least 2 characters to search your GitHub repositories.')
                                        : __('No matching repositories.') }}
                                </flux:text>
                            @endforelse
                        </div>
                    @else
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Connect your GitHub account from Settings to add repositories.') }}
                        </flux:text>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Close') }}</flux:button>
                        </flux:modal.close>
                    </div>
                </div>
            </flux:modal>
        @endif
    @endif
</div>
