<?php

use App\Enums\AgentRunStatus;
use App\Enums\TaskStatus;
use App\Models\Subtask;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Subtask')] class extends Component {
    public int $project_id;

    public int $story_id;

    public int $subtask_id;

    public function mount(int $project, int $story, int $subtask): void
    {
        $this->project_id = (int) $project;
        $this->story_id = (int) $story;
        $this->subtask_id = (int) $subtask;

        $loaded = $this->subtask;
        abort_unless($loaded, 404);

        $user = Auth::user();
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function subtask(): ?Subtask
    {
        return Subtask::query()
            ->whereHas('task.plan.story.feature', fn ($q) => $q
                ->where('project_id', $this->project_id)
                ->whereIn('project_id', Auth::user()->accessibleProjectIds())
            )
            ->whereHas('task.plan.story', fn ($q) => $q->where('id', $this->story_id))
            ->with([
                'task.plan.story.feature.project',
                'task.plan',
                'task.acceptanceCriterion',
                'task.scenario',
                'task.dependencies',
                'agentRuns.repo',
                'proposedByRun',
            ])
            ->find($this->subtask_id);
    }

    /**
     * AgentRuns ordered newest-first. Multiple-runs case is the race-mode
     * leaderboard (ADR-0006); the single-run case is the common path.
     */
    #[Computed]
    public function runs()
    {
        return $this->subtask?->agentRuns->sortByDesc('id')->values() ?? collect();
    }
}; ?>

<div class="flex p-6">
    @if (! $this->subtask)
        <flux:text class="text-zinc-500">{{ __('Subtask not found.') }}</flux:text>
    @else
        @php
            $subtask = $this->subtask;
            $task = $subtask->task;
            $story = $task->plan->story;
            $feature = $story->feature;
            $project = $feature->project;
            $runs = $this->runs;
            $isRace = $runs->count() > 1 && $runs->groupBy('runnable_id')->count() === 1;
        @endphp

        @php
            $rail = match ($subtask->status) {
                TaskStatus::Done => 'run_complete',
                TaskStatus::InProgress => 'running',
                TaskStatus::Blocked => 'run_failed',
                default => 'draft',
            };
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
                <span class="text-zinc-700 dark:text-zinc-300" aria-current="page">{{ $subtask->name }}</span>
            </nav>

            <div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <flux:badge size="sm">T{{ $task->position }}.{{ $subtask->position }}</flux:badge>
                    <flux:badge size="sm">{{ $subtask->status->value }}</flux:badge>
                    <flux:badge size="sm">T{{ $task->position }}: {{ $task->name }}</flux:badge>
                    @if ($task->plan)
                        <flux:badge size="sm">{{ __('plan') }} v{{ $task->plan->version }}</flux:badge>
                    @endif
                    @if ($subtask->proposed_by_run_id)
                        <flux:badge color="amber" size="sm" title="{{ __('Appended mid-run by') }} #{{ $subtask->proposed_by_run_id }} (ADR-0005)">{{ __('appended') }}</flux:badge>
                    @endif
                </div>
                <flux:heading size="xl" class="mt-2">{{ $subtask->name }}</flux:heading>
                @if ($subtask->description)
                    <x-markdown :content="$subtask->description" class="mt-2" />
                @endif
                @if ($task->acceptanceCriterion || $task->scenario)
                    <div class="mt-3 flex flex-col gap-2">
                        @if ($task->acceptanceCriterion)
                            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                                <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Acceptance criterion') }} AC{{ $task->acceptanceCriterion->position }}</div>
                                <div class="mt-1">{{ $task->acceptanceCriterion->statement }}</div>
                            </div>
                        @endif
                        @if ($task->scenario)
                            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                                <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Scenario') }} {{ $task->scenario->position }}</div>
                                <div class="mt-1 font-medium">{{ $task->scenario->name }}</div>
                                @if ($task->scenario->given_text)
                                    <div class="mt-1"><span class="font-medium">{{ __('Given') }}</span> {{ $task->scenario->given_text }}</div>
                                @endif
                                @if ($task->scenario->when_text)
                                    <div class="mt-1"><span class="font-medium">{{ __('When') }}</span> {{ $task->scenario->when_text }}</div>
                                @endif
                                @if ($task->scenario->then_text)
                                    <div class="mt-1"><span class="font-medium">{{ __('Then') }}</span> {{ $task->scenario->then_text }}</div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <section class="flex flex-col gap-3" data-section="runs">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg">{{ __('Runs') }}</flux:heading>
                    @if ($isRace)
                        <flux:badge color="sky" title="{{ __('Race mode: multiple drivers ran in parallel; the first success wins (ADR-0006)') }}">{{ __('Race · :n drivers', ['n' => $runs->count()]) }}</flux:badge>
                    @endif
                </div>
                @forelse ($runs as $run)
                    @php
                        $runRail = match ($run->status) {
                            AgentRunStatus::Succeeded => 'run_complete',
                            AgentRunStatus::Running, AgentRunStatus::Queued => 'running',
                            AgentRunStatus::Failed, AgentRunStatus::Aborted => 'run_failed',
                            default => 'draft',
                        };
                        $duration = $run->started_at && $run->finished_at
                            ? $run->started_at->diffInSeconds($run->finished_at)
                            : null;
                    @endphp
                    <x-run.list-row
                        :run="$run"
                        :href="route('runs.show', ['project' => $project, 'story' => $story, 'subtask' => $subtask, 'run' => $run])"
                        :rail-state="$runRail"
                        :duration="$duration"
                    />
                @empty
                    <flux:text class="text-zinc-500">{{ __('No runs yet for this subtask.') }}</flux:text>
                @endforelse
            </section>
        </div>
    @endif
</div>
