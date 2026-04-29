<?php

use App\Enums\RepoProvider;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Repo;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Repositories')] class extends Component {
    public ?int $editing_id = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|url|max:1024')]
    public string $url = '';

    #[Validate('required|string')]
    public string $provider = 'github';

    #[Validate('required|string|max:255')]
    public string $default_branch = 'main';

    #[Validate('nullable|string|max:1024')]
    public string $access_token = '';

    #[Validate('nullable|string|max:1024')]
    public string $webhook_secret = '';

    #[Computed]
    public function workspace()
    {
        return Auth::user()->currentWorkspace();
    }

    #[Computed]
    public function project(): ?Project
    {
        $id = Auth::user()->current_project_id;

        return $id
            ? Project::query()->whereIn('id', Auth::user()->accessibleProjectIds())->with('team')->find($id)
            : null;
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
        $workspace = $this->workspace;
        if (! $workspace) {
            return collect();
        }

        return Repo::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, array{role: ?string, is_primary: bool}>
     */
    public function attachedToProject(): array
    {
        if (! $this->project) {
            return [];
        }

        return $this->project->repos()->withPivot('role', 'is_primary')->get()
            ->mapWithKeys(fn ($r) => [$r->id => [
                'role' => $r->pivot->role,
                'is_primary' => (bool) $r->pivot->is_primary,
            ]])
            ->all();
    }

    public function edit(int $repoId): void
    {
        $repo = $this->workspaceRepo($repoId);
        $this->editing_id = $repo->id;
        $this->name = $repo->name;
        $this->url = $repo->url;
        $this->provider = $repo->provider->value;
        $this->default_branch = $repo->default_branch ?: 'main';
        $this->access_token = '';
        $this->webhook_secret = '';
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->reset(['name', 'url', 'access_token', 'webhook_secret']);
        $this->provider = 'github';
        $this->default_branch = 'main';
    }

    public function save(): void
    {
        abort_unless($this->canManage, 403);
        abort_unless($this->workspace, 422);

        $this->validate();

        $attrs = [
            'workspace_id' => $this->workspace->id,
            'name' => $this->name,
            'provider' => RepoProvider::from($this->provider),
            'url' => $this->url,
            'default_branch' => $this->default_branch ?: 'main',
        ];
        if ($this->access_token !== '') {
            $attrs['access_token'] = $this->access_token;
        }
        if ($this->webhook_secret !== '') {
            $attrs['webhook_secret'] = $this->webhook_secret;
        }

        if ($this->editing_id) {
            $repo = $this->workspaceRepo($this->editing_id);
            $repo->fill($attrs)->save();
        } else {
            $existing = Repo::query()
                ->where('workspace_id', $this->workspace->id)
                ->where('url', $this->url)
                ->first();
            $existing
                ? $existing->fill($attrs)->save()
                : Repo::create($attrs);
        }

        $this->cancelEdit();
    }

    public function attach(int $repoId): void
    {
        abort_unless($this->project, 422);
        abort_unless($this->canManage, 403);

        $repo = $this->workspaceRepo($repoId);
        $this->project->attachRepo($repo, role: null, primary: false);
    }

    public function detach(int $repoId): void
    {
        abort_unless($this->project, 422);
        abort_unless($this->canManage, 403);

        $this->project->repos()->detach($repoId);
    }

    public function setPrimary(int $repoId): void
    {
        abort_unless($this->project, 422);
        abort_unless($this->canManage, 403);

        $repo = $this->project->repos()->findOrFail($repoId);
        $this->project->setPrimaryRepo($repo);
    }

    public function delete(int $repoId): void
    {
        abort_unless($this->canManage, 403);

        $repo = $this->workspaceRepo($repoId);
        if ($repo->projects()->exists()) {
            $this->addError('repos', __('Detach this repo from all projects before deleting.'));

            return;
        }

        $repo->delete();
    }

    public function useGithubToken(): void
    {
        $token = Auth::user()->github_token;
        abort_unless($token, 422);
        $this->access_token = $token;
    }

    private function workspaceRepo(int $repoId): Repo
    {
        $repo = Repo::query()->where('workspace_id', $this->workspace?->id)->find($repoId);
        abort_unless($repo, 404);

        return $repo;
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Repos') }}</flux:heading>

    @if ($this->project)
        <flux:text class="text-sm text-zinc-500">{{ __('Attach / detach to') }} {{ $this->project->name }}. {{ __('Edit and delete affect the workspace.') }}</flux:text>
    @endif

    @error('repos')
        <flux:text class="text-red-600">{{ $message }}</flux:text>
    @enderror

    @php($attachments = $this->attachedToProject())

    <section class="flex flex-col gap-3">
        @forelse ($this->repos() as $repo)
            @php($commit = $repo->latestCommit())
            @php($attached = $attachments[$repo->id] ?? null)
            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="solid">{{ $repo->name }}</flux:badge>
                    <flux:badge>{{ $repo->provider->value }}</flux:badge>
                    @if ($attached)
                        <flux:badge color="green">{{ __('attached') }}</flux:badge>
                        @if ($attached['is_primary'])
                            <flux:badge>{{ __('primary') }}</flux:badge>
                        @endif
                        @if ($attached['role'])
                            <flux:badge>{{ $attached['role'] }}</flux:badge>
                        @endif
                    @endif
                    @if (! $repo->access_token)
                        <flux:badge>{{ __('missing token') }}</flux:badge>
                    @endif
                    @if (! $repo->webhook_secret)
                        <flux:badge>{{ __('no webhook') }}</flux:badge>
                    @endif
                    @if ($commit)
                        @if ($commit['html_url'] ?? null)
                            <a href="{{ $commit['html_url'] }}" target="_blank" rel="noopener">
                                <flux:badge color="green">{{ $repo->default_branch }}@{{ $commit['short'] }}</flux:badge>
                            </a>
                        @else
                            <flux:badge color="green">{{ $repo->default_branch }}@{{ $commit['short'] }}</flux:badge>
                        @endif
                    @elseif ($repo->access_token && $repo->provider->value === 'github')
                        <flux:badge color="red">{{ __('token check failed') }}</flux:badge>
                    @endif
                    @php($projectCount = $repo->projects()->count())
                    @if ($projectCount && ! $this->project)
                        <flux:badge>{{ trans_choice(':count project|:count projects', $projectCount) }}</flux:badge>
                    @endif
                </div>
                <flux:text class="mt-1 text-sm">{{ $repo->url }}</flux:text>
                <flux:text class="text-xs text-zinc-500">{{ __('default branch') }}: {{ $repo->default_branch }}</flux:text>
                @if ($this->canManage)
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($this->project)
                            @if ($attached)
                                @unless ($attached['is_primary'])
                                    <flux:button wire:click="setPrimary({{ $repo->id }})">{{ __('Make primary') }}</flux:button>
                                @endunless
                                <flux:button variant="ghost" wire:click="detach({{ $repo->id }})">{{ __('Detach') }}</flux:button>
                            @else
                                <flux:button wire:click="attach({{ $repo->id }})">{{ __('Attach') }}</flux:button>
                            @endif
                        @endif
                        <flux:button variant="ghost" wire:click="edit({{ $repo->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button variant="danger" wire:click="delete({{ $repo->id }})">{{ __('Delete') }}</flux:button>
                    </div>
                @endif
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No repositories yet. Add one below.') }}</flux:text>
        @endforelse
    </section>

    @if ($this->canManage)
        <section class="flex flex-col gap-3">
            <flux:heading size="lg">
                {{ $editing_id ? __('Edit repository') : __('Add a repository') }}
            </flux:heading>
            <form wire:submit.prevent="save" class="flex flex-col gap-3">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="url" :label="__('URL')" placeholder="https://github.com/owner/repo.git" required />
                <flux:select wire:model="provider" :label="__('Provider')">
                    <flux:select.option value="github">GitHub</flux:select.option>
                    <flux:select.option value="gitlab">GitLab</flux:select.option>
                    <flux:select.option value="bitbucket">Bitbucket</flux:select.option>
                    <flux:select.option value="generic">Generic</flux:select.option>
                </flux:select>
                <flux:input wire:model="default_branch" :label="__('Default branch')" />
                <div class="flex flex-col gap-1">
                    <flux:input
                        type="password"
                        wire:model="access_token"
                        :label="$editing_id ? __('Access token (leave blank to keep)') : __('Access token')"
                    />
                    <flux:text class="text-xs text-zinc-500">{{ __('Used by the agent to clone, push, and open PRs. GitHub: needs repo scope (and admin:repo_hook for webhook install).') }}</flux:text>
                    @if (auth()->user()->github_id && $provider === 'github' && ! $access_token)
                        <flux:button type="button" variant="ghost" size="sm" wire:click="useGithubToken" class="self-start">
                            {{ __('Use my GitHub token') }}
                        </flux:button>
                    @endif
                </div>
                <flux:input
                    type="password"
                    wire:model="webhook_secret"
                    :label="$editing_id ? __('Webhook secret (leave blank to keep)') : __('Webhook secret')"
                />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">{{ $editing_id ? __('Save changes') : __('Save') }}</flux:button>
                    @if ($editing_id)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </section>
    @endif
</div>
