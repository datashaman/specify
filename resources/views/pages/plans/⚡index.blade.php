<?php

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Plans')] class extends Component {
    use WithPagination;

    public int $project_id;

    #[Url(as: 'status')]
    public ?string $status = null;

    #[Url(as: 'current')]
    public bool $currentOnly = true;

    public function mount(int $project): void
    {
        $user = Auth::user();
        abort_unless(in_array((int) $project, $user->accessibleProjectIds(), true), 404);
        $this->project_id = (int) $project;

        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function projectName(): ?string
    {
        return Auth::user()->accessibleProjectsInCurrentWorkspace()->firstWhere('id', $this->project_id)?->name;
    }

    #[Computed]
    public function plans()
    {
        return Plan::query()
            ->whereHas('story.feature', fn ($q) => $q->where('project_id', $this->project_id))
            ->when($this->currentOnly, fn ($q) => $q->whereHas('story', fn ($qq) => $qq->whereColumn('stories.current_plan_id', 'plans.id')))
            ->when($this->status, fn ($q, $status) => $q->where('status', $status))
            ->with(['story.feature.project', 'tasks.subtasks'])
            ->latest('updated_at')
            ->paginate(20);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Plans') }}</flux:heading>
            @if ($this->projectName)
                <flux:text class="text-sm text-zinc-500">{{ $this->projectName }} · {{ __('implementation layer') }}</flux:text>
            @endif
        </div>
        <a href="{{ route('approvals.index', ['project' => $this->project_id]) }}" wire:navigate>
            <flux:button>{{ __('Open approvals board') }}</flux:button>
        </a>
    </div>

    <div class="grid gap-3 md:grid-cols-3">
        <flux:select wire:model.live="status" :placeholder="__('All plan statuses')">
            <flux:select.option value="">{{ __('All plan statuses') }}</flux:select.option>
            @foreach (PlanStatus::cases() as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->value }}</flux:select.option>
            @endforeach
        </flux:select>

        <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
            <input type="checkbox" wire:model.live="currentOnly">
            <span>{{ __('Current plans only') }}</span>
        </label>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($this->plans as $plan)
            @php
                $taskCount = $plan->tasks->count();
                $subtaskCount = $plan->tasks->sum(fn ($task) => $task->subtasks->count());
                $story = $plan->story;
            @endphp
            <a href="{{ route('stories.show', ['project' => $story->feature->project_id, 'story' => $story->id]) }}" wire:navigate>
                <flux:card class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge>{{ $plan->status->value }}</flux:badge>
                        <flux:badge>{{ __('v') }}{{ $plan->version }}</flux:badge>
                        <flux:badge>{{ __('rev') }} {{ $plan->revision }}</flux:badge>
                        @if ((int) $story->current_plan_id === (int) $plan->id)
                            <flux:badge variant="solid">{{ __('current') }}</flux:badge>
                        @endif
                        <flux:badge>{{ $taskCount }} {{ __('tasks') }} · {{ $subtaskCount }} {{ __('subtasks') }}</flux:badge>
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $plan->updated_at?->diffForHumans() }}</flux:text>
                    </div>

                    <div class="mt-3 flex flex-col gap-1">
                        <flux:heading>{{ $plan->name ?? __('Plan') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ $story->feature->name }} · {{ $story->name }}</flux:text>
                    </div>

                    @if ($plan->summary)
                        <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $plan->summary }}</flux:text>
                    @elseif ($story->intent || $story->outcome)
                        <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            @if ($story->intent)
                                <div><span class="font-medium">{{ __('I want') }}</span> {{ $story->intent }}</div>
                            @endif
                            @if ($story->outcome)
                                <div><span class="font-medium">{{ __('So that') }}</span> {{ $story->outcome }}</div>
                            @endif
                        </div>
                    @endif
                </flux:card>
            </a>
        @empty
            <flux:text class="text-zinc-500">{{ __('No plans found.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->plans->links() }}
</div>
