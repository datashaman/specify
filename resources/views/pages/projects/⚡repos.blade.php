<?php

use App\Enums\RepoProvider;
use App\Models\Project;
use App\Models\Repo;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Repositories')] class extends Component {
    public int $project_id;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|url|max:1024')]
    public string $url = '';

    #[Validate('required|string')]
    public string $provider = 'github';

    #[Validate('required|string|max:255')]
    public string $default_branch = 'main';

    #[Validate('nullable|string|max:255')]
    public string $role = '';

    public bool $is_primary = false;

    #[Validate('nullable|string|max:1024')]
    public string $access_token = '';

    #[Validate('nullable|string|max:1024')]
    public string $webhook_secret = '';

    public function mount(int $project): void
    {
        $this->project_id = $project;
        abort_unless($this->project, 404);
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
    public function repos()
    {
        return $this->project
            ? $this->project->repos()->withPivot('role', 'is_primary')->orderByPivot('is_primary', 'desc')->get()
            : collect();
    }

    public function attach(): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless(Auth::user()->canApproveInProject($project), 403);

        $this->validate();

        $workspaceId = $project->team->workspace_id;
        $repo = Repo::query()
            ->where('workspace_id', $workspaceId)
            ->where('url', $this->url)
            ->first();

        $attrs = [
            'workspace_id' => $workspaceId,
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

        if ($repo) {
            $repo->fill($attrs)->save();
        } else {
            $repo = Repo::create($attrs);
        }

        $project->attachRepo($repo, role: $this->role ?: null, primary: $this->is_primary);

        $this->reset(['name', 'url', 'role', 'is_primary', 'access_token', 'webhook_secret']);
        $this->provider = 'github';
        $this->default_branch = 'main';
        unset($this->repos);
    }

    public function setPrimary(int $repoId): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless(Auth::user()->canApproveInProject($project), 403);

        $repo = $project->repos()->findOrFail($repoId);
        $project->setPrimaryRepo($repo);
        unset($this->repos);
    }

    public function detach(int $repoId): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless(Auth::user()->canApproveInProject($project), 403);

        $project->repos()->detach($repoId);
        unset($this->repos);
    }

    public function useGithubToken(): void
    {
        $token = Auth::user()->github_token;
        abort_unless($token, 422);
        $this->access_token = $token;
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    @if (! $this->project)
        <flux:text class="text-zinc-500">{{ __('Project not found.') }}</flux:text>
    @else
        <flux:heading size="xl">{{ __('Repositories for') }} {{ $this->project->name }}</flux:heading>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Attached') }}</flux:heading>
            @forelse ($this->repos as $repo)
                @php($commit = $repo->latestCommit())
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">{{ $repo->name }}</flux:badge>
                        <flux:badge>{{ $repo->provider->value }}</flux:badge>
                        @if ($repo->pivot->is_primary)
                            <flux:badge>{{ __('primary') }}</flux:badge>
                        @endif
                        @if ($repo->pivot->role)
                            <flux:badge>{{ $repo->pivot->role }}</flux:badge>
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
                    </div>
                    <flux:text class="mt-1 text-sm">{{ $repo->url }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">{{ __('default branch') }}: {{ $repo->default_branch }}</flux:text>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @unless ($repo->pivot->is_primary)
                            <flux:button wire:click="setPrimary({{ $repo->id }})">{{ __('Make primary') }}</flux:button>
                        @endunless
                        <flux:button variant="danger" wire:click="detach({{ $repo->id }})">{{ __('Detach') }}</flux:button>
                    </div>
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No repositories attached. Add one below.') }}</flux:text>
            @endforelse
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Attach a repository') }}</flux:heading>
            <form wire:submit.prevent="attach" class="flex flex-col gap-3">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="url" :label="__('URL')" placeholder="https://github.com/owner/repo.git" required />
                <flux:select wire:model="provider" :label="__('Provider')">
                    <flux:select.option value="github">GitHub</flux:select.option>
                    <flux:select.option value="gitlab">GitLab</flux:select.option>
                    <flux:select.option value="bitbucket">Bitbucket</flux:select.option>
                    <flux:select.option value="generic">Generic</flux:select.option>
                </flux:select>
                <flux:input wire:model="default_branch" :label="__('Default branch')" />
                <flux:input wire:model="role" :label="__('Role (optional)')" placeholder="backend / server / worker" />
                <flux:checkbox wire:model="is_primary" :label="__('Primary repo for this project')" />
                <div class="flex flex-col gap-1">
                    <flux:input type="password" wire:model="access_token" :label="__('Access token')" />
                    <flux:text class="text-xs text-zinc-500">{{ __('Used by the agent to clone, push, and open PRs. GitHub: needs repo scope (and admin:repo_hook for webhook install).') }}</flux:text>
                    @if (auth()->user()->github_id && $provider === 'github' && ! $access_token)
                        <flux:button type="button" variant="ghost" size="sm" wire:click="useGithubToken" class="self-start">
                            {{ __('Use my GitHub token') }}
                        </flux:button>
                    @endif
                </div>
                <flux:input type="password" wire:model="webhook_secret" :label="__('Webhook secret')" />
                <flux:button type="submit" variant="primary">{{ __('Attach') }}</flux:button>
            </form>
        </section>
    @endif
</div>
