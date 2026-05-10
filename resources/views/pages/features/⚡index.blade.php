<?php

use App\Enums\FeatureStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Services\Ordering\PositionReorderer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Features')] class extends Component {
    public int $project_id;

    #[Validate('required|string|max:255')]
    public string $newFeatureName = '';

    #[Validate('nullable|string')]
    public string $newFeatureDescription = '';

    public function mount(int $project): void
    {
        $this->project_id = $project;
        abort_unless($this->project, 404);
    }

    #[Computed]
    public function project(): ?Project
    {
        return Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->find($this->project_id);
    }

    #[Computed]
    public function features()
    {
        return $this->project
            ? $this->project->features()
                ->withCount('stories')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
            : collect();
    }

    public function canManageFeatures(): bool
    {
        $project = $this->project;

        return $project !== null && Auth::user()->canApproveInProject($project);
    }

    /**
     * @param  array<int, int>  $orderedIds  Feature IDs in the new visual order.
     */
    public function reorderFeatures(array $orderedIds): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless(Auth::user()->canApproveInProject($project), 403);

        app(PositionReorderer::class)->reorder('features', 'project_id', (int) $project->id, $orderedIds);

        unset($this->features);
    }

    public function createFeature(): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless(Auth::user()->canApproveInProject($project), 403);

        $this->validate(['newFeatureName' => 'required|string|max:255']);

        Feature::create([
            'project_id' => $project->id,
            'name' => $this->newFeatureName,
            'description' => $this->newFeatureDescription ?: null,
            'status' => FeatureStatus::Proposed,
        ]);

        $this->reset(['newFeatureName', 'newFeatureDescription']);
        unset($this->features);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <div class="flex items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Features') }}</flux:heading>
        @if ($this->canManageFeatures())
            <flux:modal.trigger name="new-feature-modal">
                <flux:button variant="primary">{{ __('+ New feature') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @php $canReorderFeatures = $this->canManageFeatures() && $this->features->count() > 1; @endphp
    <div
        class="flex flex-col gap-3"
        @if ($canReorderFeatures)
            x-data
            x-sortable="$wire.reorderFeatures"
        @endif
        data-features-list
    >
        @forelse ($this->features as $feature)
            <div
                wire:key="feature-{{ $feature->id }}"
                @if ($canReorderFeatures) data-sortable-id="{{ $feature->id }}" @endif
            >
                <flux:card>
                    <div class="flex items-center gap-3">
                        @if ($canReorderFeatures)
                            <button
                                type="button"
                                data-sortable-handle
                                class="flex flex-none cursor-grab items-center text-zinc-400 hover:text-zinc-600 active:cursor-grabbing dark:hover:text-zinc-300"
                                aria-label="{{ __('Drag to reorder') }}"
                                title="{{ __('Drag to reorder') }}"
                            >
                                <flux:icon.bars-3 class="size-5" />
                            </button>
                        @endif
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('features.show', ['project' => $this->project_id, 'feature' => $feature->id]) }}" wire:navigate>
                                <flux:heading>{{ $feature->name }}</flux:heading>
                            </a>
                            @if ($feature->description)
                                <x-markdown :content="$feature->description" class="mt-1" />
                            @endif
                        </div>
                        <flux:badge>{{ $feature->stories_count }} {{ __('stories') }}</flux:badge>
                    </div>
                </flux:card>
            </div>
        @empty
            <flux:text class="text-zinc-500">{{ __('No features yet.') }}</flux:text>
        @endforelse
    </div>

    @if ($this->canManageFeatures())
        <flux:modal name="new-feature-modal" class="md:w-96">
            <form wire:submit.prevent="createFeature" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('New feature') }}</flux:heading>
                <flux:input wire:model="newFeatureName" :label="__('Name')" required />
                <flux:textarea wire:model="newFeatureDescription" :label="__('Description (optional)')" rows="2" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Create feature') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
