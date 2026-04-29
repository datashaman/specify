<?php

use App\Enums\FeatureStatus;
use App\Models\Feature;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Project')] class extends Component {
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
            ->with('team.workspace')
            ->find($this->project_id);
    }

    #[Computed]
    public function features()
    {
        return $this->project
            ? $this->project->features()->withCount('stories')->orderBy('name')->get()
            : collect();
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
    @if (! $this->project)
        <flux:text class="text-zinc-500">{{ __('Project not found.') }}</flux:text>
    @else
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ $this->project->name }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">
                    {{ $this->project->team->workspace->name }}
                </flux:text>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Features') }}</flux:heading>
            @forelse ($this->features as $feature)
                <flux:card>
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <a href="{{ route('features.show', ['project' => $this->project->id, 'feature' => $feature->id]) }}" wire:navigate>
                                <flux:heading>{{ $feature->name }}</flux:heading>
                            </a>
                            @if ($feature->description)
                                <flux:text class="mt-1 text-sm">{{ $feature->description }}</flux:text>
                            @endif
                        </div>
                        <flux:badge>{{ $feature->stories_count }} {{ __('stories') }}</flux:badge>
                    </div>
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No features yet. Create one below.') }}</flux:text>
            @endforelse
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('New feature') }}</flux:heading>
            <form wire:submit.prevent="createFeature" class="flex flex-col gap-3">
                <flux:input wire:model="newFeatureName" :label="__('Name')" required />
                <flux:textarea wire:model="newFeatureDescription" :label="__('Description (optional)')" rows="2" />
                <flux:button type="submit" variant="primary">{{ __('Create feature') }}</flux:button>
            </form>
        </section>
    @endif
</div>
