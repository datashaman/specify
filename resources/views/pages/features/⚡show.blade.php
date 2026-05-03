<?php

use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\Feature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            ? $this->feature->stories()
                ->with('creator', 'tasks', 'currentPlan:id,story_id,version,name,status')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
            : collect();
    }

    /**
     * @param  array<int, int>  $orderedIds  Story IDs in the new visual order.
     */
    public function reorderStories(array $orderedIds): void
    {
        $feature = $this->feature;
        abort_unless($feature, 404);
        abort_unless(Auth::user()->canApproveInProject($feature->project), 403);

        $owned = $feature->stories()->pluck('id')->all();
        $clean = array_values(array_filter(
            array_map('intval', $orderedIds),
            fn (int $id) => in_array($id, $owned, true),
        ));

        if (count($clean) !== count($owned)) {
            return;
        }

        DB::transaction(function () use ($clean) {
            foreach ($clean as $i => $id) {
                DB::table('stories')->where('id', $id)->update(['position' => $i + 1]);
            }
        });

        unset($this->stories);
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
                            :tally="(string) $totalStories"
                            :label="__('stories')"
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
                @php $canReorder = $this->canEditFeature() && $this->stories->count() > 1; @endphp
                <div
                    class="flex flex-col gap-3"
                    @if ($canReorder)
                        x-data
                        x-sortable="$wire.reorderStories"
                    @endif
                    data-stories-list
                >
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
                        @endphp
                        <x-story.feature-row
                            :story="$story"
                            :state="$rowState"
                            :href="route('stories.show', ['project' => $feature->project_id, 'story' => $story->id])"
                            :can-reorder="$canReorder"
                            :sortable-id="$story->id"
                            :wire-key="'story-'.$story->id"
                        />
                    @empty
                        <flux:text class="text-zinc-500">{{ __('No stories yet for this feature.') }}</flux:text>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
</div>
