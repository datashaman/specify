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

    public bool $editing = false;

    #[Validate('required|string|max:255')]
    public string $editName = '';

    #[Validate('nullable|string|max:1000')]
    public string $editDescription = '';

    public function mount(int $project): void
    {
        $this->project_id = $project;
        $loaded = $this->project;
        abort_unless($loaded, 404);

        $user = Auth::user();
        if ((int) $user->current_project_id !== (int) $project) {
            $user->switchProject($loaded);
        }
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

    public function canEditProject(): bool
    {
        $project = $this->project;

        return $project !== null && Auth::user()->canApproveInProject($project);
    }

    public function startEdit(): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless($this->canEditProject(), 403);

        $this->editName = (string) $project->name;
        $this->editDescription = (string) ($project->description ?? '');
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->editName = '';
        $this->editDescription = '';
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless($this->canEditProject(), 403);

        $this->validate([
            'editName' => 'required|string|max:255',
            'editDescription' => 'nullable|string|max:1000',
        ]);

        $project->update([
            'name' => trim($this->editName),
            'description' => $this->editDescription !== '' ? $this->editDescription : null,
        ]);

        $this->editing = false;
        unset($this->project);
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
        <section data-section="project-header" class="flex flex-col gap-2">
            @if ($editing)
                <flux:input wire:model="editName" :label="__('Name')" required />
                <flux:textarea wire:model="editDescription" :label="__('Description')" rows="3" />
                <div class="flex items-center gap-2">
                    <flux:button wire:click="saveEdit" variant="primary">{{ __('Save') }}</flux:button>
                    <flux:button wire:click="cancelEdit" variant="ghost">{{ __('Cancel') }}</flux:button>
                </div>
            @else
                <div class="flex items-start justify-between gap-3">
                    <flux:heading size="xl">{{ $this->project->name }}</flux:heading>
                    @if ($this->canEditProject())
                        <flux:button wire:click="startEdit" size="sm" icon="pencil-square">{{ __('Edit') }}</flux:button>
                    @endif
                </div>
                @if ($this->project->description)
                    <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $this->project->description }}</flux:text>
                @endif
            @endif
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="xl">{{ __('Features') }}</flux:heading>
            @forelse ($this->features as $feature)
                <flux:card>
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <a href="{{ route('features.show', ['project' => $this->project->id, 'feature' => $feature->id]) }}" wire:navigate>
                                <flux:heading>{{ $feature->name }}</flux:heading>
                            </a>
                            @if ($feature->description)
                                <x-markdown :content="$feature->description" class="mt-1" />
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
