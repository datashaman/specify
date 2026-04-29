<?php

use App\Enums\AgentRunStatus;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function projectIds()
    {
        return Auth::user()->accessibleProjectIds();
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
    public function pendingPlanCount()
    {
        return Plan::query()
            ->where('status', PlanStatus::PendingApproval)
            ->whereHas('story.feature', fn ($q) => $q->whereIn('project_id', $this->projectIds))
            ->count();
    }

    #[Computed]
    public function executingPlanCount()
    {
        return Plan::query()
            ->where('status', PlanStatus::Executing)
            ->whereHas('story.feature', fn ($q) => $q->whereIn('project_id', $this->projectIds))
            ->count();
    }

    #[Computed]
    public function failedRunCount()
    {
        return AgentRun::query()
            ->where('status', AgentRunStatus::Failed)
            ->whereHasMorph('runnable', [Task::class], function ($q) {
                $q->whereHas('plan.story.feature', fn ($qq) => $qq->whereIn('project_id', $this->projectIds));
            })
            ->count();
    }

    #[Computed]
    public function recentRuns()
    {
        return AgentRun::query()
            ->whereHasMorph('runnable', [Task::class], function ($q) {
                $q->whereHas('plan.story.feature', fn ($qq) => $qq->whereIn('project_id', $this->projectIds));
            })
            ->with('runnable.plan.story.feature.project', 'repo')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function projects()
    {
        return Project::query()
            ->whereIn('id', $this->projectIds)
            ->withCount(['features', 'repos'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function reposNeedingToken()
    {
        return Repo::query()
            ->whereHas('projects', fn ($q) => $q->whereIn('projects.id', $this->projectIds))
            ->where(function ($q) {
                $q->whereNull('access_token')->orWhere('access_token', '');
            })
            ->count();
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
        <a href="{{ route('inbox') }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Pending stories') }}</div>
            <div class="mt-1 text-3xl font-semibold">{{ $this->pendingStoryCount }}</div>
        </a>
        <a href="{{ route('inbox') }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Pending plans') }}</div>
            <div class="mt-1 text-3xl font-semibold">{{ $this->pendingPlanCount }}</div>
        </a>
        <a href="{{ route('runs.index') }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Executing plans') }}</div>
            <div class="mt-1 text-3xl font-semibold">{{ $this->executingPlanCount }}</div>
        </a>
        <a href="{{ route('runs.index', ['status' => 'failed']) }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Failed runs') }}</div>
            <div class="mt-1 text-3xl font-semibold {{ $this->failedRunCount > 0 ? 'text-red-600' : '' }}">{{ $this->failedRunCount }}</div>
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Recent runs') }}</flux:heading>
            @forelse ($this->recentRuns as $run)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">#{{ $run->id }}</flux:badge>
                        <flux:badge>{{ $run->status->value }}</flux:badge>
                        @if ($run->repo)
                            <flux:badge>{{ $run->repo->name }}</flux:badge>
                        @endif
                        @if ($run->finished_at)
                            <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                        @endif
                    </div>
                    <flux:heading class="mt-2">
                        @if ($run->runnable)
                            {{ $run->runnable->name }}
                        @else
                            {{ __('Run') }}
                        @endif
                    </flux:heading>
                    @if ($url = $run->output['pull_request_url'] ?? null)
                        <flux:text class="mt-1">
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ $url }}</a>
                        </flux:text>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
            @endforelse
            <a href="{{ route('runs.index') }}" wire:navigate class="text-sm underline">{{ __('See all runs') }} &rarr;</a>
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Projects') }}</flux:heading>
            @forelse ($this->projects as $project)
                <flux:card>
                    <flux:heading>{{ $project->name }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500">
                        {{ $project->features_count }} {{ __('features') }} &middot; {{ $project->repos_count }} {{ __('repos') }}
                    </flux:text>
                </flux:card>
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
