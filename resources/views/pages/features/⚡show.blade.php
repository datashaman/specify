<?php

use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\Feature;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Feature')] class extends Component {
    public int $project_id;

    public int $feature_id;

    public bool $editing = false;

    #[Validate('required|string|max:255')]
    public string $editName = '';

    #[Validate('nullable|string')]
    public string $editDescription = '';

    #[Validate('nullable|string')]
    public string $editNotes = '';

    public function mount(int $project, int $feature): void
    {
        $this->project_id = $project;
        $this->feature_id = $feature;
        abort_unless($this->feature, 404);
    }

    public function startEdit(): void
    {
        $feature = $this->feature;
        abort_unless($feature, 404);
        abort_unless(Auth::user()->canApproveInProject($feature->project), 403);

        $this->editName = (string) $feature->name;
        $this->editDescription = (string) $feature->description;
        $this->editNotes = (string) $feature->notes;
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->editName = '';
        $this->editDescription = '';
        $this->editNotes = '';
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $feature = $this->feature;
        abort_unless($feature, 404);
        abort_unless(Auth::user()->canApproveInProject($feature->project), 403);

        $this->validate();

        $feature->update([
            'name' => trim($this->editName),
            'description' => $this->editDescription ?: null,
            'notes' => $this->editNotes ?: null,
        ]);

        $this->editing = false;
        unset($this->feature);
    }

    public function canEditFeature(): bool
    {
        $feature = $this->feature;

        return $feature !== null && Auth::user()->canApproveInProject($feature->project);
    }

    #[Computed]
    public function feature(): ?Feature
    {
        return Feature::query()
            ->where('project_id', $this->project_id)
            ->whereHas('project', fn ($q) => $q->whereIn('id', Auth::user()->accessibleProjectIds()))
            ->with('project')
            ->find($this->feature_id);
    }

    #[Computed]
    public function stories()
    {
        return $this->feature
            ? $this->feature->stories()->with('creator', 'tasks:id,story_id,status')->latest('updated_at')->get()
            : collect();
    }

    /**
     * Tally child stories by status. Drives both the rail-state roll-up
     * and the chip strip in the header. Keys are StoryStatus->value.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function storyTally(): array
    {
        $tally = [];
        foreach ($this->stories as $story) {
            $key = $story->status->value;
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        return $tally;
    }

    /**
     * Roll-up rail state. "running" wins if any story has an active
     * subtask run; otherwise we walk the priority ladder. Empty feature
     * is "draft".
     */
    #[Computed]
    public function railState(): string
    {
        if ($this->stories->isEmpty()) {
            return 'draft';
        }

        $hasActiveRun = $this->stories->contains(function ($story) {
            return $story->tasks->isNotEmpty()
                && $story->status === StoryStatus::Approved
                && $story->tasks->contains(fn ($t) => $t->status === TaskStatus::InProgress || $t->status === TaskStatus::Pending);
        });
        if ($hasActiveRun) {
            return 'running';
        }

        $statuses = $this->stories->pluck('status')->map(fn ($s) => $s->value);

        if ($statuses->every(fn ($s) => $s === StoryStatus::Done->value)) {
            return 'run_complete';
        }
        if ($statuses->contains(StoryStatus::ChangesRequested->value)) {
            return 'changes_requested';
        }
        if ($statuses->contains(StoryStatus::PendingApproval->value)) {
            return 'pending';
        }
        if ($statuses->contains(StoryStatus::Approved->value)) {
            return 'approved';
        }

        return 'draft';
    }
}; ?>

<div class="flex p-6">
    @if (! $this->feature)
        <flux:text class="text-zinc-500">{{ __('Feature not found.') }}</flux:text>
    @else
        @php
            $feature = $this->feature;
            $tally = $this->storyTally;
            $totalStories = array_sum($tally);
        @endphp

        <x-rail :state="$this->railState" class="mr-4" />

        <div class="flex min-w-0 max-w-4xl flex-1 flex-col gap-6">
            <div>
                <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-sm text-zinc-500" data-section="breadcrumb">
                    <a href="{{ route('projects.show', $feature->project) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $feature->project->name }}</a>
                    <span aria-hidden="true">›</span>
                    <span class="text-zinc-700 dark:text-zinc-300" aria-current="page">{{ $feature->name }}</span>
                </nav>

                @if ($editing)
                    <div class="mt-3 flex flex-col gap-3">
                        <flux:input wire:model="editName" :label="__('Name')" />
                        <flux:textarea wire:model="editDescription" :label="__('Description (markdown supported)')" rows="6" />
                        <flux:textarea wire:model="editNotes" :label="__('Notes (markdown supported)')" rows="4" />
                        <div class="flex items-center gap-2">
                            <flux:button wire:click="saveEdit" wire:target="saveEdit" wire:loading.attr="disabled" variant="primary">
                                <span wire:loading.remove wire:target="saveEdit">{{ __('Save') }}</span>
                                <span wire:loading wire:target="saveEdit">{{ __('Saving…') }}</span>
                            </flux:button>
                            <flux:button wire:click="cancelEdit" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </div>
                    </div>
                @else
                    <div class="mt-2 flex items-start justify-between gap-3">
                        <flux:heading size="xl">{{ $feature->name }}</flux:heading>
                        <div class="flex items-center gap-2">
                            @if ($this->canEditFeature())
                                <flux:button wire:click="startEdit" size="sm" icon="pencil-square">{{ __('Edit') }}</flux:button>
                            @endif
                            <a href="{{ route('stories.create', ['project' => $feature->project_id, 'feature_id' => $feature->id]) }}" wire:navigate>
                                <flux:button variant="primary">{{ __('+ New story') }}</flux:button>
                            </a>
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2" data-section="story-tally">
                        <x-state-pill
                            :state="$this->railState"
                            :tally="$totalStories > 0 ? (string) $totalStories : null"
                            :label="__($totalStories === 1 ? ':n story' : ':n stories', ['n' => $totalStories])"
                        />
                        @foreach ($tally as $statusValue => $count)
                            @php
                                $pillState = match ($statusValue) {
                                    StoryStatus::Draft->value, StoryStatus::ProposedByAI->value => 'draft',
                                    StoryStatus::PendingApproval->value => 'pending',
                                    StoryStatus::Approved->value => 'approved',
                                    StoryStatus::ChangesRequested->value => 'changes_requested',
                                    StoryStatus::Rejected->value, StoryStatus::Cancelled->value => 'rejected',
                                    StoryStatus::Done->value => 'run_complete',
                                    default => 'draft',
                                };
                            @endphp
                            <x-state-pill
                                :state="$pillState"
                                :tally="(string) $count"
                                :label="$statusValue"
                                data-tally-status="{{ $statusValue }}"
                            />
                        @endforeach
                    </div>

                    @if ($feature->description)
                        <x-markdown :content="$feature->description" class="mt-3" />
                    @endif
                    @if ($feature->notes)
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Notes') }}</summary>
                            <x-markdown :content="$feature->notes" class="mt-2" />
                        </details>
                    @endif
                @endif
            </div>

            <section class="flex flex-col gap-3" data-section="stories">
                <flux:heading size="lg">{{ __('Stories') }}</flux:heading>
                @forelse ($this->stories as $story)
                    @php
                        $rowState = match ($story->status->value) {
                            StoryStatus::Draft->value, StoryStatus::ProposedByAI->value => 'draft',
                            StoryStatus::PendingApproval->value => 'pending',
                            StoryStatus::Approved->value => 'approved',
                            StoryStatus::ChangesRequested->value => 'changes_requested',
                            StoryStatus::Rejected->value, StoryStatus::Cancelled->value => 'rejected',
                            StoryStatus::Done->value => 'run_complete',
                            default => 'draft',
                        };
                        $tasksTotal = $story->tasks->count();
                        $tasksDone = $story->tasks->filter(fn ($t) => $t->status === TaskStatus::Done)->count();
                    @endphp
                    <a href="{{ route('stories.show', ['project' => $feature->project_id, 'story' => $story->id]) }}" wire:navigate class="flex items-stretch gap-3 rounded-lg border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50" data-story-row="{{ $story->id }}">
                        <x-rail :state="$rowState" class="!w-0.5" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-state-pill :state="$rowState" :label="$story->status->value" />
                                <flux:badge size="sm">rev {{ $story->revision }}</flux:badge>
                                @if ($story->creator)
                                    <flux:badge size="sm">{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                                @endif
                                @if ($tasksTotal > 0)
                                    <flux:badge size="sm">{{ $tasksDone }}/{{ $tasksTotal }} {{ __('tasks') }}</flux:badge>
                                @endif
                                <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at?->diffForHumans() }}</flux:text>
                            </div>
                            <flux:heading class="mt-2">{{ $story->name }}</flux:heading>
                            @if ($story->description)
                                <x-markdown :content="$story->description" class="mt-1 text-sm text-zinc-600 dark:text-zinc-400" />
                            @endif
                        </div>
                    </a>
                @empty
                    <flux:text class="text-zinc-500">{{ __('No stories yet for this feature.') }}</flux:text>
                @endforelse
            </section>
        </div>
    @endif
</div>
