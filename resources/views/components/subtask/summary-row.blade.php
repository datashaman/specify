@props([
    'subtask',
    'href',
    'taskPosition',
    'railState',
    'latestRun' => null,
])

<a
    href="{{ $href }}"
    wire:navigate
    class="flex items-stretch gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50"
    data-subtask-id="{{ $subtask->id }}"
>
    <x-rail :state="$railState" class="!w-0.5" />
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <flux:badge size="sm">T{{ $taskPosition }}.{{ $subtask->position }}</flux:badge>
            <x-state-pill :state="$railState" :label="$subtask->status->value" />
            @if ($latestRun)
                <flux:badge size="sm">{{ __('run') }} #{{ $latestRun->id }}</flux:badge>
            @endif
            @if ($subtask->updated_at)
                <flux:text class="ml-auto text-xs text-zinc-500">{{ $subtask->updated_at->diffForHumans(short: true) }}</flux:text>
            @endif
        </div>
        <flux:heading size="sm" class="mt-1">{{ $subtask->name }}</flux:heading>
        @if ($subtask->description)
            <x-markdown :content="$subtask->description" class="mt-1 text-xs text-zinc-500" />
        @endif
    </div>
</a>
