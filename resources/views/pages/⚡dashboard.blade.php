<?php

use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function projectIds()
    {
        return Auth::user()->scopedProjectIds();
    }

    #[Computed]
    public function pendingStoryCount()
    {
        return Story::query()
            ->where('status', StoryStatus::PendingApproval)
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $this->projectIds))
            ->count();
    }

    #[Computed]
    public function executingStoryCount()
    {
        return Story::query()
            ->where('status', StoryStatus::Approved)
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $this->projectIds))
            ->whereHas('tasks.subtasks', fn ($q) => $q->whereIn('status', [TaskStatus::InProgress, TaskStatus::Pending]))
            ->count();
    }

    #[Computed]
    public function failedRunCount()
    {
        return AgentRun::query()
            ->where('status', AgentRunStatus::Failed)
            ->whereHasMorph('runnable', [Subtask::class], function ($q) {
                $q->whereHas('task.story.feature', fn ($qq) => $qq->whereIn('project_id', $this->projectIds));
            })
            ->count();
    }

    #[Computed]
    public function recentRuns()
    {
        return AgentRun::query()
            ->whereHasMorph('runnable', [Subtask::class], function ($q) {
                $q->whereHas('task.story.feature', fn ($qq) => $qq->whereIn('project_id', $this->projectIds));
            })
            ->with('runnable.task.story.feature.project', 'repo')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function awaitingMyApproval()
    {
        $user = Auth::user();
        $approvableProjectIds = collect($this->projectIds)
            ->filter(function ($projectId) use ($user) {
                $project = Project::find($projectId);

                return $project && $user->canApproveInProject($project);
            })
            ->values()
            ->all();

        if ($approvableProjectIds === []) {
            return collect();
        }

        return Story::query()
            ->where('status', StoryStatus::PendingApproval)
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $approvableProjectIds))
            ->with('feature.project', 'creator')
            ->latest('updated_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function projects()
    {
        $ids = Auth::user()->accessibleProjectsInCurrentWorkspace()->pluck('id');

        return Project::query()
            ->whereIn('id', $ids)
            ->withCount(['features', 'repos'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function reposNeedingToken()
    {
        $ids = Auth::user()->accessibleProjectsInCurrentWorkspace()->pluck('id');

        return Repo::query()
            ->whereHas('projects', fn ($q) => $q->whereIn('projects.id', $ids))
            ->where(function ($q) {
                $q->whereNull('access_token')->orWhere('access_token', '');
            })
            ->count();
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    @php
        $currentProjectId = auth()->user()->current_project_id;
        $runsHref = $currentProjectId ? route('runs.index', ['project' => $currentProjectId]) : route('projects.index');
        $failedRunsHref = $currentProjectId ? route('runs.index', ['project' => $currentProjectId, 'status' => 'failed']) : route('projects.index');
    @endphp

    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <a href="{{ route('triage') }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Pending stories') }}</div>
            <div class="mt-1 text-3xl font-semibold">{{ $this->pendingStoryCount }}</div>
        </a>
        <a href="{{ $runsHref }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Executing stories') }}</div>
            <div class="mt-1 text-3xl font-semibold">{{ $this->executingStoryCount }}</div>
        </a>
        <a href="{{ $failedRunsHref }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Failed runs') }}</div>
            <div class="mt-1 text-3xl font-semibold {{ $this->failedRunCount > 0 ? 'text-red-600' : '' }}">{{ $this->failedRunCount }}</div>
        </a>
    </div>

    @if ($this->awaitingMyApproval->isNotEmpty())
        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Awaiting your approval') }}</flux:heading>
            @foreach ($this->awaitingMyApproval as $story)
                <a
                    href="{{ route('stories.show', ['project' => $story->feature->project_id, 'story' => $story->id]) }}"
                    wire:navigate
                    class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50/50 p-3 hover:bg-amber-50 dark:border-amber-900/40 dark:bg-amber-950/20 dark:hover:bg-amber-950/40"
                >
                    <flux:icon name="exclamation-triangle" class="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <flux:text class="truncate font-medium">{{ $story->name }}</flux:text>
                            <flux:badge size="sm">{{ __('rev') }} {{ $story->revision }}</flux:badge>
                        </div>
                        <flux:text class="text-xs text-zinc-500">
                            {{ $story->feature->project->name }} · {{ $story->feature->name }}
                            @if ($story->creator)
                                · {{ __('by') }} {{ $story->creator->name }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:text class="text-xs text-zinc-500">{{ $story->updated_at?->diffForHumans(short: true) }}</flux:text>
                </a>
            @endforeach
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between">
                <flux:heading size="lg">{{ __('Recent runs') }}</flux:heading>
                <a href="{{ $runsHref }}" wire:navigate class="text-xs text-zinc-500 hover:underline">{{ __('See all') }} &rarr;</a>
            </div>
            @forelse ($this->recentRuns as $run)
                @php
                    $runHref = $run->runnable && $run->runnable->task?->story
                        ? route('runs.show', [
                            'project' => $run->runnable->task->story->feature->project_id,
                            'story' => $run->runnable->task->story->id,
                            'subtask' => $run->runnable->id,
                            'run' => $run->id,
                        ])
                        : null;
                @endphp
                <a
                    @if ($runHref) href="{{ $runHref }}" wire:navigate @endif
                    @class([
                        'flex items-center gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700',
                        'hover:bg-zinc-50 dark:hover:bg-zinc-900' => $runHref,
                    ])
                >
                    <flux:badge variant="solid" size="sm">#{{ $run->id }}</flux:badge>
                    <flux:badge size="sm">{{ $run->status->value }}</flux:badge>
                    <flux:text class="truncate text-sm">
                        @if ($run->runnable)
                            {{ $run->runnable->name }}
                        @else
                            {{ __('Run') }}
                        @endif
                    </flux:text>
                    @if ($run->finished_at)
                        <flux:text class="ml-auto shrink-0 text-xs text-zinc-500">{{ $run->finished_at->diffForHumans(short: true) }}</flux:text>
                    @endif
                </a>
            @empty
                <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
            @endforelse
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Projects') }}</flux:heading>
            @forelse ($this->projects as $project)
                <a
                    href="{{ route('projects.show', $project) }}"
                    wire:navigate
                    class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900"
                >
                    <div class="min-w-0">
                        <flux:text class="truncate font-medium">{{ $project->name }}</flux:text>
                        <flux:text class="text-xs text-zinc-500">
                            {{ $project->features_count }} {{ __('features') }} &middot; {{ $project->repos_count }} {{ __('repos') }}
                        </flux:text>
                    </div>
                    <flux:icon name="arrow-right" class="size-4 shrink-0 text-zinc-400" />
                </a>
            @empty
                <flux:text class="text-zinc-500">{{ __('No projects in your teams.') }}</flux:text>
            @endforelse
            @if ($this->reposNeedingToken > 0)
                <flux:text class="text-sm text-amber-600">
                    {{ $this->reposNeedingToken }} {{ __('repo(s) missing access_token — PRs will fail until configured.') }}
                </flux:text>
            @endif
        </section>
    </div>
</div>
