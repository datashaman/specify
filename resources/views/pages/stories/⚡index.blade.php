<?php

use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Stories')] class extends Component {
    use WithPagination;

    #[Url(as: 'status')]
    public ?string $status = null;

    #[Computed]
    public function stories()
    {
        $projectIds = Auth::user()->scopedProjectIds();

        return Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->with(['feature.project', 'creator', 'tasks:id,story_id,status'])
            ->latest('updated_at')
            ->paginate(25);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Stories') }}</flux:heading>
        <a href="{{ route('stories.create') }}" wire:navigate>
            <flux:button variant="primary">{{ __('+ New story') }}</flux:button>
        </a>
    </div>

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="status" :placeholder="__('All statuses')">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (StoryStatus::cases() as $s)
                <flux:select.option value="{{ $s->value }}">{{ $s->value }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($this->stories as $story)
            <a href="{{ route('stories.show', $story) }}" wire:navigate>
                <flux:card class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">{{ $story->feature->project->name }}</flux:badge>
                        <flux:badge>{{ $story->status->value }}</flux:badge>
                        <flux:badge>rev {{ $story->revision }}</flux:badge>
                        @if ($story->creator)
                            <flux:badge>{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                        @endif
                        @php
                            $tasksTotal = $story->tasks->count();
                            $tasksDone = $story->tasks->filter(fn ($t) => $t->status === \App\Enums\TaskStatus::Done)->count();
                        @endphp
                        @if ($tasksTotal > 0)
                            <flux:badge>{{ $tasksDone }}/{{ $tasksTotal }} {{ __('tasks') }}</flux:badge>
                        @endif
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at?->diffForHumans() }}</flux:text>
                    </div>
                    <flux:heading class="mt-2">{{ $story->name }}</flux:heading>
                    <x-markdown :content="$story->description" class="mt-1" />
                </flux:card>
            </a>
        @empty
            <flux:text class="text-zinc-500">{{ __('No stories found.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->stories->links() }}
</div>
