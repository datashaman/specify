@props([
    'run',
    'href',
    'railState',
    'duration' => null,
])

<a
    href="{{ $href }}"
    wire:navigate
    class="flex items-stretch gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50"
    data-run-row="{{ $run->id }}"
>
    <x-rail :state="$railState" class="!w-0.5" />
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <flux:badge variant="solid" size="sm">{{ __('run') }} #{{ $run->id }}</flux:badge>
            <x-state-pill :state="$railState" :label="$run->status->value" />
            @if ($run->executor_driver)
                <flux:badge size="sm">{{ $run->executor_driver }}</flux:badge>
            @endif
            @if ($run->kind && $run->kind !== 'execute')
                <flux:badge color="purple" size="sm">{{ $run->kind }}</flux:badge>
            @endif
            @if ($duration !== null)
                <flux:badge size="sm">{{ $duration }}s</flux:badge>
            @endif
            @if ($run->finished_at)
                <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
            @elseif ($run->started_at)
                <flux:text class="ml-auto text-xs text-zinc-500">{{ __('started') }} {{ $run->started_at->diffForHumans() }}</flux:text>
            @endif
        </div>
        @if ($url = $run->output['pull_request_url'] ?? null)
            <flux:text class="mt-1 truncate text-xs">
                <span class="text-zinc-500">{{ __('PR:') }}</span> {{ $url }}
            </flux:text>
        @endif
        @if ($run->error_message)
            <flux:text class="mt-1 truncate text-xs text-rose-600 dark:text-rose-400">{{ \Illuminate\Support\Str::limit($run->error_message, 200) }}</flux:text>
        @endif
    </div>
</a>
