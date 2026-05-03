@props([
    'story',
    'href' => null,
    'cardClass' => 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50',
    'renderDescription' => true,
    'wireNavigate' => true,
])

@php
    $tasks = $story->relationLoaded('tasks') ? $story->tasks : collect();
    $tasksTotal = $tasks->count();
    $tasksDone = $tasks->filter(fn ($task) => $task->status === \App\Enums\TaskStatus::Done)->count();
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($wireNavigate) wire:navigate @endif>
@endif
    <flux:card class="{{ $cardClass }}">
        @isset($meta)
            {{ $meta }}
        @else
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge>{{ $story->status->value }}</flux:badge>
                <flux:badge>{{ __('rev') }} {{ $story->revision }}</flux:badge>
                @if ($story->creator)
                    <flux:badge>{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                @endif
                @if ($tasksTotal > 0)
                    <flux:badge>{{ $tasksDone }}/{{ $tasksTotal }} {{ __('tasks') }}</flux:badge>
                @endif
                @if ($story->updated_at)
                    <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at->diffForHumans() }}</flux:text>
                @endif
            </div>
        @endisset

        <flux:heading class="mt-2">{{ $story->name }}</flux:heading>

        @if ($renderDescription && $story->description)
            <x-markdown :content="$story->description" class="mt-1" />
        @endif

        {{ $slot }}
    </flux:card>
@if ($href)
    </a>
@endif
