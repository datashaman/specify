<?php

use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Story')] class extends Component {
    public int $story_id;

    public function mount(int $story): void
    {
        $this->story_id = $story;
        abort_unless($this->story, 404);
    }

    #[Computed]
    public function story(): ?Story
    {
        return Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', Auth::user()->accessibleProjectIds()))
            ->with([
                'feature.project',
                'creator',
                'acceptanceCriteria',
                'tasks.acceptanceCriterion',
                'tasks.dependencies',
                'tasks.subtasks',
                'approvals.approver',
            ])
            ->find($this->story_id);
    }

    #[Computed]
    public function runs()
    {
        if (! $this->story) {
            return collect();
        }

        $taskIds = $this->story->tasks->pluck('id');
        $subtaskIds = $this->story->tasks->flatMap->subtasks->pluck('id');

        return AgentRun::query()
            ->where(function ($q) use ($taskIds, $subtaskIds) {
                $q->where(function ($qq) {
                    $qq->where('runnable_type', Story::class)->where('runnable_id', $this->story_id);
                });
                if ($taskIds->isNotEmpty()) {
                    $q->orWhere(function ($qq) use ($taskIds) {
                        $qq->where('runnable_type', Task::class)->whereIn('runnable_id', $taskIds);
                    });
                }
                if ($subtaskIds->isNotEmpty()) {
                    $q->orWhere(function ($qq) use ($subtaskIds) {
                        $qq->where('runnable_type', Subtask::class)->whereIn('runnable_id', $subtaskIds);
                    });
                }
            })
            ->with('repo', 'runnable')
            ->latest('id')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    @if (! $this->story)
        <flux:text class="text-zinc-500">{{ __('Story not found.') }}</flux:text>
    @else
        @php
            $story = $this->story;
            $project = $story->feature->project;
        @endphp
        <div>
            <a href="{{ route('features.show', ['project' => $project->id, 'feature' => $story->feature_id]) }}" wire:navigate class="text-sm text-zinc-500 underline">
                &larr; {{ $project->name }} / {{ $story->feature->name }}
            </a>
            <flux:heading size="xl" class="mt-2">{{ $story->name }}</flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge>{{ $story->status->value }}</flux:badge>
                <flux:badge>rev {{ $story->revision }}</flux:badge>
                @if ($story->creator)
                    <flux:badge>{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                @endif
            </div>
            <x-markdown :content="$story->description" class="mt-3" />
            @if ($story->notes)
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Notes') }}</summary>
                    <x-markdown :content="$story->notes" class="mt-2" />
                </details>
            @endif
        </div>

        @if ($story->acceptanceCriteria->isNotEmpty())
            <section class="flex flex-col gap-2">
                <flux:heading size="lg">{{ __('Acceptance criteria') }}</flux:heading>
                <ul class="list-disc pl-5 text-sm">
                    @foreach ($story->acceptanceCriteria as $ac)
                        <li>{{ $ac->criterion }} @if ($ac->met)<flux:badge class="ml-2">{{ __('met') }}</flux:badge>@endif</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Tasks') }}</flux:heading>
            @forelse ($story->tasks->sortBy('position') as $task)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">#{{ $task->position }}</flux:badge>
                        <flux:badge>{{ $task->status->value }}</flux:badge>
                        @if ($task->acceptanceCriterion)
                            <flux:badge>{{ __('AC') }} #{{ $task->acceptanceCriterion->position }}</flux:badge>
                        @endif
                        @php
                            $deps = $task->dependencies->map(fn ($d) => '#'.$d->position);
                        @endphp
                        @if ($deps->isNotEmpty())
                            <flux:badge>{{ __('depends on') }} {{ $deps->implode(', ') }}</flux:badge>
                        @endif
                    </div>
                    <flux:heading class="mt-2">{{ $task->name }}</flux:heading>
                    @if ($task->acceptanceCriterion)
                        <flux:text class="mt-1 text-sm text-zinc-500"><em>{{ $task->acceptanceCriterion->criterion }}</em></flux:text>
                    @endif
                    @if ($task->description)
                        <x-markdown :content="$task->description" class="mt-2 text-sm" />
                    @endif
                    @if ($task->subtasks->isNotEmpty())
                        <ol class="mt-3 list-decimal pl-5 text-sm">
                            @foreach ($task->subtasks->sortBy('position') as $sub)
                                <li class="py-1">
                                    <span class="font-medium">{{ $sub->name }}</span>
                                    <flux:badge class="ml-2">{{ $sub->status->value }}</flux:badge>
                                    @if ($sub->description)
                                        <x-markdown :content="$sub->description" class="mt-1 text-xs text-zinc-500" />
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No tasks yet.') }}</flux:text>
            @endforelse
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Runs') }}</flux:heading>
            @forelse ($this->runs as $run)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">#{{ $run->id }}</flux:badge>
                        <flux:badge>{{ $run->status->value }}</flux:badge>
                        @if ($run->repo)
                            <flux:badge>{{ $run->repo->name }}</flux:badge>
                        @endif
                        @if ($run->working_branch)
                            <flux:badge>{{ $run->working_branch }}</flux:badge>
                        @endif
                        @if ($run->finished_at)
                            <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                        @endif
                    </div>
                    @if ($run->runnable instanceof Subtask)
                        <flux:text class="mt-2 text-sm">{{ $run->runnable->name }}</flux:text>
                    @elseif ($run->runnable)
                        <flux:text class="mt-2 text-sm">{{ $run->runnable->name }}</flux:text>
                    @endif
                    @if ($url = $run->output['pull_request_url'] ?? null)
                        <flux:text class="mt-1">
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ $url }}</a>
                        </flux:text>
                    @endif
                    @if ($run->error_message)
                        <flux:text class="mt-1 text-red-600">{{ $run->error_message }}</flux:text>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
            @endforelse
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Story approvals') }}</flux:heading>
            @forelse ($story->approvals->sortByDesc('created_at') as $approval)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge>{{ $approval->decision->value }}</flux:badge>
                        <flux:badge>rev {{ $approval->story_revision }}</flux:badge>
                        <flux:text class="text-sm">{{ $approval->approver?->name ?? 'unknown' }}</flux:text>
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $approval->created_at?->diffForHumans() }}</flux:text>
                    </div>
                    @if ($approval->notes)
                        <flux:text class="mt-1 text-sm">{{ $approval->notes }}</flux:text>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No story approvals yet.') }}</flux:text>
            @endforelse
        </section>
    @endif
</div>
