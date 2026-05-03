@props([
    'repo',
    'multipleRepos' => false,
    'isPrimary' => false,
    'commit' => null,
    'canManage' => false,
])

@php($homeUrl = preg_replace('/\.git$/', '', $repo->url))

<flux:card>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge variant="solid">{{ $repo->name }}</flux:badge>
                @if ($multipleRepos && $isPrimary)
                    <flux:badge color="green">{{ __('primary') }}</flux:badge>
                @endif
                @if ($repo->webhook_secret)
                    <flux:tooltip :content="__('Webhook installed')">
                        <flux:icon name="bolt" class="size-4 text-emerald-500" />
                    </flux:tooltip>
                @else
                    <flux:tooltip :content="__('No webhook installed')">
                        <flux:icon name="bolt-slash" class="size-4 text-zinc-400" />
                    </flux:tooltip>
                @endif
                @if ($commit)
                    @if ($commit['html_url'] ?? null)
                        <a href="{{ $commit['html_url'] }}" target="_blank" rel="noopener">
                            <flux:badge color="green">{{ $repo->default_branch }}{{ '@'.$commit['short'] }}</flux:badge>
                        </a>
                    @else
                        <flux:badge color="green">{{ $repo->default_branch }}{{ '@'.$commit['short'] }}</flux:badge>
                    @endif
                @elseif ($repo->access_token && $repo->provider === \App\Enums\RepoProvider::Github)
                    <flux:tooltip :content="$repo->latestCommitError() ?? __('token check failed')">
                        <flux:badge color="red">{{ __('token check failed') }}</flux:badge>
                    </flux:tooltip>
                @endif
            </div>
            <a href="{{ $homeUrl }}" target="_blank" rel="noopener" class="text-sm text-zinc-500 hover:underline">{{ $homeUrl }}</a>
        </div>
        @if ($canManage)
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                @if ($multipleRepos && ! $isPrimary)
                    <flux:button wire:click="setPrimary({{ $repo->id }})">{{ __('Make primary') }}</flux:button>
                @endif
                @if (! $repo->webhook_secret && $repo->provider === \App\Enums\RepoProvider::Github && $repo->access_token)
                    <flux:button wire:click="installWebhook({{ $repo->id }})">{{ __('Install webhook') }}</flux:button>
                @endif
                <flux:modal.trigger name="remove-repo-modal">
                    <flux:button variant="danger" wire:click="confirmRemove({{ $repo->id }})">
                        {{ __('Remove') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </div>
</flux:card>
