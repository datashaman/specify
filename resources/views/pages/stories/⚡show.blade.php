<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Story;
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
                'plans.tasks.dependencies',
                'plans.approvals.approver',
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

        $taskIds = $this->story->plans->flatMap->tasks->pluck('id');

        return AgentRun::query()
            ->where(function ($q) use ($taskIds) {
                $q->where(function ($qq) use ($taskIds) {
                    $qq->where('runnable_type', Task::class)->whereIn('runnable_id', $taskIds);
                })->orWhere(function ($qq) {
                    $qq->where('runnable_type', Story::class)->where('runnable_id', $this->story_id);
                });
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
            <flux:text class="mt-3">{{ $story->description }}</flux:text>
        </div>

        @if ($story->acceptanceCriteria->isNotEmpty())
            <section class="flex flex-col gap-2">
                <flux:heading size="lg">{{ __('Acceptance criteria') }}</flux:heading>
                <ul class="list-disc pl-5 text-sm">
                    @foreach ($story->acceptanceCriteria as $ac)
                        <li>{{ $ac->criterion }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Plans') }}</flux:heading>
            @forelse ($story->plans->sortByDesc('version') as $plan)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">v{{ $plan->version }}</flux:badge>
                        <flux:badge>{{ $plan->status->value }}</flux:badge>
                        @if ($story->current_plan_id === $plan->id)
                            <flux:badge>{{ __('current') }}</flux:badge>
                        @endif
                    </div>
                    @if ($plan->summary)
                        <flux:text class="mt-2">{{ $plan->summary }}</flux:text>
                    @endif
                    @if ($plan->tasks->isNotEmpty())
                        <table class="mt-3 w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                                <tr>
                                    <th class="w-10 py-1">#</th>
                                    <th class="py-1">{{ __('Task') }}</th>
                                    <th class="py-1">{{ __('Status') }}</th>
                                    <th class="py-1">{{ __('Depends on') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($plan->tasks->sortBy('position') as $task)
                                    <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                        <td class="py-1 align-top text-zinc-500">{{ $task->position }}</td>
                                        <td class="py-1 align-top">{{ $task->name }}</td>
                                        <td class="py-1 align-top text-zinc-500">{{ $task->status->value }}</td>
                                        <td class="py-1 align-top text-zinc-500">
                                            @php
                                                $deps = $task->dependencies->map(fn ($d) => '#'.$d->position.' '.$d->name);
                                            @endphp
                                            {{ $deps->isEmpty() ? '—' : $deps->implode(', ') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No plans yet.') }}</flux:text>
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
                    @if ($run->runnable instanceof Task)
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
