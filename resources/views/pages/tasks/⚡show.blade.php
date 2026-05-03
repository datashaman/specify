<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Task')] class extends Component {
    public int $project_id;

    public int $story_id;

    public int $task_id;

    public function mount(int $project, int $story, int $task): void
    {
        $this->project_id = (int) $project;
        $this->story_id = (int) $story;
        $this->task_id = (int) $task;

        $loaded = $this->task;
        abort_unless($loaded, 404);

        $user = Auth::user();
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function task(): ?Task
    {
        return Task::query()
            ->whereHas('story.feature', fn ($q) => $q
                ->where('project_id', $this->project_id)
                ->whereIn('project_id', Auth::user()->accessibleProjectIds())
            )
            ->whereHas('story', fn ($q) => $q->where('id', $this->story_id))
            ->with([
                'story.feature.project',
                'plan',
                'acceptanceCriterion',
                'scenario',
                'dependencies',
                'subtasks.agentRuns.repo',
                'subtasks.proposedByRun',
            ])
            ->find($this->task_id);
    }
}; ?>

<div class="flex p-6">
    @if (! $this->task)
        <flux:text class="text-zinc-500">{{ __('Task not found.') }}</flux:text>
    @else
        @php
            $task = $this->task;
            $story = $task->story;
            $feature = $story->feature;
            $project = $feature->project;
            $ac = $task->acceptanceCriterion;
            $rail = match ($task->status) {
                TaskStatus::Done => 'run_complete',
                TaskStatus::InProgress => 'running',
                TaskStatus::Blocked => 'run_failed',
                default => 'draft',
            };
            $deps = $task->dependencies->map(fn ($d) => 'T'.$d->position);
            $subtaskTotal = $task->subtasks->count();
            $subtaskDone = $task->subtasks->filter(fn ($s) => $s->status === TaskStatus::Done)->count();
        @endphp

        <x-rail :state="$rail" class="mr-4" />

        <div class="flex min-w-0 max-w-4xl flex-1 flex-col gap-6">
            <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-sm text-zinc-500" data-section="breadcrumb">
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $project->name }}</a>
                <span aria-hidden="true">›</span>
                <a href="{{ route('features.show', ['project' => $project, 'feature' => $feature]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $feature->name }}</a>
                <span aria-hidden="true">›</span>
                <a href="{{ route('stories.show', ['project' => $project, 'story' => $story]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $story->name }}</a>
                <span aria-hidden="true">›</span>
                <span class="text-zinc-700 dark:text-zinc-300" aria-current="page">{{ $task->name }}</span>
            </nav>

            <div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <flux:badge size="sm">T{{ $task->position }}</flux:badge>
                    <x-state-pill :state="$rail" :label="$task->status->value" />
                    @if ($subtaskTotal > 0)
                        <flux:badge>{{ $subtaskDone }}/{{ $subtaskTotal }} {{ __('subtasks') }}</flux:badge>
                    @endif
                    @if ($task->plan)
                        <flux:badge size="sm">{{ __('plan') }} v{{ $task->plan->version }}</flux:badge>
                    @endif
                    @if ($deps->isNotEmpty())
                        <flux:badge size="sm">{{ __('depends on') }} {{ $deps->implode(', ') }}</flux:badge>
                    @endif
                </div>
                <flux:heading size="xl" class="mt-2">{{ $task->name }}</flux:heading>
                @if ($task->description)
                    <x-markdown :content="$task->description" class="mt-2" />
                @endif
                @if ($ac)
                    <div class="mt-3 rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                        <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Acceptance criterion') }} AC{{ $ac->position }}</flux:text>
                        <flux:text class="mt-1 text-sm">{{ $ac->statement }}</flux:text>
                    </div>
                @endif
                @if ($task->scenario)
                    <div class="mt-3 rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                        <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Scenario') }} {{ $task->scenario->position }}</flux:text>
                        <flux:text class="mt-1 text-sm font-medium">{{ $task->scenario->name }}</flux:text>
                        @if ($task->scenario->given_text)
                            <flux:text class="mt-1 text-sm"><span class="font-medium">{{ __('Given') }}</span> {{ $task->scenario->given_text }}</flux:text>
                        @endif
                        @if ($task->scenario->when_text)
                            <flux:text class="mt-1 text-sm"><span class="font-medium">{{ __('When') }}</span> {{ $task->scenario->when_text }}</flux:text>
                        @endif
                        @if ($task->scenario->then_text)
                            <flux:text class="mt-1 text-sm"><span class="font-medium">{{ __('Then') }}</span> {{ $task->scenario->then_text }}</flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <section class="flex flex-col gap-3" data-section="subtasks">
                <flux:heading size="lg">{{ __('Subtasks') }}</flux:heading>
                @forelse ($task->subtasks->sortBy('position') as $sub)
                    @php
                        $subRail = match ($sub->status) {
                            TaskStatus::Done => 'run_complete',
                            TaskStatus::InProgress => 'running',
                            TaskStatus::Blocked => 'run_failed',
                            default => 'draft',
                        };
                        $latestRun = $sub->agentRuns->sortByDesc('id')->first();
                    @endphp
                    <x-subtask.summary-row
                        :subtask="$sub"
                        :href="route('subtasks.show', ['project' => $project->id, 'story' => $story->id, 'subtask' => $sub->id])"
                        :task-position="$task->position"
                        :rail-state="$subRail"
                        :latest-run="$latestRun"
                    />
                @empty
                    <flux:text class="text-zinc-500">{{ __('No subtasks yet.') }}</flux:text>
                @endforelse
            </section>
        </div>
    @endif
</div>
