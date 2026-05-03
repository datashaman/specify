@props([
    'story',
    'href',
    'state',
    'canReorder' => false,
    'sortableId' => null,
    'wireKey' => null,
])

@php
    $tasks = $story->relationLoaded('tasks') ? $story->tasks : collect();
    $tasksTotal = $tasks->count();
    $tasksDone = $tasks->filter(fn ($task) => $task->status === \App\Enums\TaskStatus::Done)->count();
@endphp

<div
    class="relative flex items-stretch gap-3 rounded-lg border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50"
    data-story-row="{{ $story->id }}"
    @if ($sortableId) data-sortable-id="{{ $sortableId }}" @endif
    @if ($wireKey) wire:key="{{ $wireKey }}" @endif
>
    <x-rail :state="$state" class="!w-0.5" />
    @if ($canReorder)
        <button
            type="button"
            data-sortable-handle
            class="relative z-10 flex flex-none cursor-grab items-center text-zinc-400 hover:text-zinc-600 active:cursor-grabbing dark:hover:text-zinc-300"
            aria-label="{{ __('Drag to reorder') }}"
            title="{{ __('Drag to reorder') }}"
        >
            <flux:icon.bars-3 class="size-5" />
        </button>
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <div class="w-24 flex-none">
                <x-state-pill :state="$state" :label="$story->status->value" />
            </div>
            <div class="w-14 flex-none">
                <flux:badge size="sm">{{ __('rev') }} {{ $story->revision }}</flux:badge>
            </div>
            <div class="w-16 flex-none">
                @if ($tasksTotal > 0)
                    <flux:badge size="sm">{{ $tasksDone }}/{{ $tasksTotal }}</flux:badge>
                @endif
            </div>
            <div class="ml-auto flex flex-none items-center gap-2">
                @if ($story->creator)
                    <flux:avatar
                        size="xs"
                        :name="$story->creator->name"
                        :initials="$story->creator->initials()"
                        :tooltip="$story->creator->name"
                    />
                @endif
                <flux:text class="text-xs text-zinc-500 tabular-nums">{{ $story->updated_at?->diffForHumans(short: true) }}</flux:text>
            </div>
        </div>
        <flux:heading class="mt-2">
            <a href="{{ $href }}" wire:navigate class="before:absolute before:inset-0 before:content-['']">{{ $story->name }}</a>
        </flux:heading>
        @if ($story->description)
            <x-markdown :content="$story->description" class="relative z-10 mt-1 text-sm text-zinc-600 dark:text-zinc-400" />
        @endif
    </div>
</div>
