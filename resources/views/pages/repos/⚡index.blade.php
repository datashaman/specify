<?php

use App\Enums\RepoProvider;
use App\Enums\TeamRole;
use App\Models\Repo;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Repositories')] class extends Component {
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
    public function canManage(): bool
    {
        $user = Auth::user();
        $teamId = $user->current_team_id;
        if (! $teamId) {
            return false;
        }
        $role = $user->roleInTeam($teamId);

        return in_array($role, [TeamRole::Owner, TeamRole::Admin], true);
    }

    #[Computed]
    public function repos()
    {
        $workspace = $this->workspace;

        return $workspace
            ? Repo::query()->where('workspace_id', $workspace->id)->orderBy('name')->get()
            : collect();
    }

    public function create(): void
    {
        abort_unless($this->canManage, 403);
        abort_unless($this->workspace, 422);

        $this->validate();

        $repo = Repo::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('url', $this->url)
            ->first();

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

        if ($repo) {
            $repo->fill($attrs)->save();
        } else {
            Repo::create($attrs);
        }

        $this->reset(['name', 'url', 'access_token', 'webhook_secret']);
        $this->provider = 'github';
        $this->default_branch = 'main';
        unset($this->repos);
    }

    public function delete(int $repoId): void
    {
        abort_unless($this->canManage, 403);

        $repo = Repo::query()->where('workspace_id', $this->workspace?->id)->find($repoId);
        abort_unless($repo, 404);

        if ($repo->projects()->exists()) {
            $this->addError('repos', __('Detach this repo from all projects before deleting.'));

            return;
        }

        $repo->delete();
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
    <flux:heading size="xl">{{ __('Repositories') }}</flux:heading>

    @error('repos')
        <flux:text class="text-red-600">{{ $message }}</flux:text>
    @enderror

    <section class="flex flex-col gap-3">
        @forelse ($this->repos as $repo)
            @php($commit = $repo->latestCommit())
            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="solid">{{ $repo->name }}</flux:badge>
                    <flux:badge>{{ $repo->provider->value }}</flux:badge>
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
                    @if ($projectCount)
                        <flux:badge>{{ trans_choice(':count project|:count projects', $projectCount) }}</flux:badge>
                    @endif
                </div>
                <flux:text class="mt-1 text-sm">{{ $repo->url }}</flux:text>
                <flux:text class="text-xs text-zinc-500">{{ __('default branch') }}: {{ $repo->default_branch }}</flux:text>
                @if ($this->canManage)
                    <div class="mt-3 flex flex-wrap gap-2">
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
            <flux:heading size="lg">{{ __('Add a repository') }}</flux:heading>
            <form wire:submit.prevent="create" class="flex flex-col gap-3">
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
                    <flux:input type="password" wire:model="access_token" :label="__('Access token')" />
                    <flux:text class="text-xs text-zinc-500">{{ __('Used by the agent to clone, push, and open PRs. GitHub: needs repo scope (and admin:repo_hook for webhook install).') }}</flux:text>
                    @if (auth()->user()->github_id && $provider === 'github' && ! $access_token)
                        <flux:button type="button" variant="ghost" size="sm" wire:click="useGithubToken" class="self-start">
                            {{ __('Use my GitHub token') }}
                        </flux:button>
                    @endif
                </div>
                <flux:input type="password" wire:model="webhook_secret" :label="__('Webhook secret')" />
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </form>
        </section>
    @endif
</div>
