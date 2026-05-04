@props([
    'story',
    'href' => null,
    'cardClass' => 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50',
    'renderDescription' => true,
    'wireNavigate' => true,
])

@php
    $tasks = $story->relationLoaded('currentPlanTasks') ? $story->currentPlanTasks : collect();
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
                    <flux:badge>{{ $tasksDone }}/{{ $tasksTotal }} {{ __('current-plan tasks') }}</flux:badge>
                @endif
                @if ($story->updated_at)
                    <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at->diffForHumans() }}</flux:text>
                @endif
            </div>
        @endisset

        <flux:heading class="mt-2">{{ $story->name }}</flux:heading>

        <div class="mt-2 flex flex-wrap gap-2 text-xs text-zinc-500">
            @if ($story->kind)
                <flux:badge size="sm">{{ $story->kind->value }}</flux:badge>
            @endif
            @if ($story->currentPlan)
                <flux:badge size="sm">{{ __('plan') }} v{{ $story->currentPlan->version }}</flux:badge>
            @endif
            @if ($story->actor)
                <flux:badge size="sm">{{ __('as') }} {{ $story->actor }}</flux:badge>
            @endif
        </div>

        @if ($renderDescription && $story->description)
            <x-markdown :content="$story->description" class="mt-1" />
        @endif

        @if ($story->intent || $story->outcome)
            <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                @if ($story->intent)
                    <div><span class="font-medium">{{ __('I want') }}</span> {{ $story->intent }}</div>
                @endif
                @if ($story->outcome)
                    <div><span class="font-medium">{{ __('So that') }}</span> {{ $story->outcome }}</div>
                @endif
            </div>
        @endif

        {{ $slot }}
    </flux:card>
@if ($href)
    </a>
@endif
