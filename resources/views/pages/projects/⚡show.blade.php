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

    #[Validate('required|string|max:255')]
    public string $deleteConfirmationName = '';

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

    public function canDeleteProject(): bool
    {
        return $this->canEditProject();
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

    public function deleteProject(): void
    {
        $project = $this->project;
        abort_unless($project, 404);
        abort_unless($this->canDeleteProject(), 403);

        $this->validate([
            'deleteConfirmationName' => ['required', 'string', 'max:255'],
        ], [], [
            'deleteConfirmationName' => __('project name'),
        ]);

        if (trim($this->deleteConfirmationName) !== $project->name) {
            $this->addError('deleteConfirmationName', __('Enter the project name exactly to confirm deletion.'));

            return;
        }

        $user = Auth::user();
        if ((int) $user->current_project_id === (int) $project->id) {
            $user->switchProject(null);
        }

        $project->delete();

        $this->redirectRoute('projects.index', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    @if (! $this->project)
        <flux:text class="text-zinc-500">{{ __('Project not found.') }}</flux:text>
    @else
        @php $canManageFeatures = Auth::user()->canApproveInProject($this->project); @endphp

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
                        <div class="flex items-center gap-2">
                            <flux:button wire:click="startEdit" size="sm" icon="pencil-square">{{ __('Edit') }}</flux:button>
                            @if ($this->canDeleteProject())
                                <flux:modal.trigger name="delete-project-modal">
                                    <flux:button size="sm" variant="danger" icon="trash">{{ __('Delete') }}</flux:button>
                                </flux:modal.trigger>
                            @endif
                        </div>
                    @endif
                </div>
                @if ($this->project->description)
                    <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $this->project->description }}</flux:text>
                @endif
            @endif
        </section>

        <section class="grid gap-3 md:grid-cols-3" data-section="project-workspaces">
            <a href="{{ route('stories.index', ['project' => $this->project->id]) }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Stories') }}</div>
                <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Browse product contracts and their current plans.') }}</div>
            </a>
            <a href="{{ route('plans.index', ['project' => $this->project->id]) }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Plans') }}</div>
                <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Work directly with the implementation layer across the project.') }}</div>
            </a>
            <a href="{{ route('approvals.index', ['project' => $this->project->id]) }}" wire:navigate class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Approvals') }}</div>
                <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Review story contracts and current plans in separate queues.') }}</div>
            </a>
        </section>

        <section id="features" class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Features') }}</flux:heading>
                @if ($canManageFeatures)
                    <flux:modal.trigger name="new-feature-modal">
                        <flux:button variant="primary">{{ __('+ New feature') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            </div>

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
                <flux:text class="text-zinc-500">{{ __('No features yet.') }}</flux:text>
            @endforelse
        </section>

        @if ($canManageFeatures)
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

            @if ($this->canDeleteProject())
                <flux:modal name="delete-project-modal" class="md:w-96">
                    <div class="flex flex-col gap-4">
                        <flux:heading size="lg">{{ __('Delete project?') }}</flux:heading>
                        <flux:text>{{ __('This permanently removes the project, its features, stories, tasks, subtasks, approvals, and repo attachments. This cannot be undone.') }}</flux:text>
                        <flux:input
                            wire:model="deleteConfirmationName"
                            :label="__('Type the project name to confirm')"
                            :placeholder="$this->project->name"
                            required
                        />
                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button wire:click="deleteProject" variant="danger" icon="trash">{{ __('Delete project') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif
        @endif
    @endif
</div>
