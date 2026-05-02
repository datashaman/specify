<?php

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
}; ?>

<div class="flex flex-col gap-6 p-6">
    @if (! $this->feature)
        <flux:text class="text-zinc-500">{{ __('Feature not found.') }}</flux:text>
    @else
        <div>
            <a href="{{ route('projects.show', $this->feature->project) }}" wire:navigate class="text-sm text-zinc-500 underline">
                &larr; {{ $this->feature->project->name }}
            </a>
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
                <div class="mt-2 flex items-center justify-between gap-3">
                    <flux:heading size="xl">{{ $this->feature->name }}</flux:heading>
                    <div class="flex items-center gap-2">
                        @if ($this->canEditFeature())
                            <flux:button wire:click="startEdit" size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                        @endif
                        <a href="{{ route('stories.create', ['project' => $this->feature->project_id, 'feature_id' => $this->feature->id]) }}" wire:navigate>
                            <flux:button variant="primary">{{ __('+ New story for this feature') }}</flux:button>
                        </a>
                    </div>
                </div>
                @if ($this->feature->description)
                    <x-markdown :content="$this->feature->description" class="mt-1" />
                @endif
                @if ($this->feature->notes)
                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Notes') }}</summary>
                        <x-markdown :content="$this->feature->notes" class="mt-2" />
                    </details>
                @endif
            @endif
        </div>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Stories') }}</flux:heading>
            @forelse ($this->stories as $story)
                <flux:card>
                    <a href="{{ route('stories.show', ['project' => $this->feature->project_id, 'story' => $story->id]) }}" wire:navigate>
                        <div class="flex flex-wrap items-center gap-2">
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
                    </a>
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No stories yet for this feature.') }}</flux:text>
            @endforelse
        </section>
    @endif
</div>
