<?php

use App\Models\Feature;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Feature')] class extends Component {
    public int $project_id;

    public int $feature_id;

    public function mount(int $project, int $feature): void
    {
        $this->project_id = $project;
        $this->feature_id = $feature;
        abort_unless($this->feature, 404);
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
            ? $this->feature->stories()->with('creator', 'currentPlan')->latest('updated_at')->get()
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
            <div class="mt-2 flex items-center justify-between">
                <flux:heading size="xl">{{ $this->feature->name }}</flux:heading>
                <a href="{{ route('stories.create', ['feature_id' => $this->feature->id]) }}" wire:navigate>
                    <flux:button variant="primary">{{ __('+ New story for this feature') }}</flux:button>
                </a>
            </div>
            @if ($this->feature->description)
                <flux:text class="mt-1">{{ $this->feature->description }}</flux:text>
            @endif
        </div>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Stories') }}</flux:heading>
            @forelse ($this->stories as $story)
                <flux:card>
                    <a href="{{ route('stories.show', $story) }}" wire:navigate>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge>{{ $story->status->value }}</flux:badge>
                            <flux:badge>rev {{ $story->revision }}</flux:badge>
                            @if ($story->creator)
                                <flux:badge>{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                            @endif
                            @if ($story->current_plan_id)
                                <flux:badge>plan v{{ $story->currentPlan?->version }}</flux:badge>
                            @endif
                            <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at?->diffForHumans() }}</flux:text>
                        </div>
                        <flux:heading class="mt-2">{{ $story->name }}</flux:heading>
                        <flux:text class="mt-1">{{ $story->description }}</flux:text>
                    </a>
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No stories yet for this feature.') }}</flux:text>
            @endforelse
        </section>
    @endif
</div>
