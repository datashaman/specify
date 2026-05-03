<?php

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
    public ?int $confirmingDeleteId = null;

    public string $deleteConfirmationName = '';

    #[Computed]
    public function projects()
    {
        $ids = Auth::user()->accessibleProjectsInCurrentWorkspace()->pluck('id');

        return Project::query()
            ->whereIn('id', $ids)
            ->with('team.workspace')
            ->withCount(['features', 'repos', 'stories'])
            ->orderBy('name')
            ->get();
    }

    public function canDeleteProject(Project $project): bool
    {
        return Auth::user()->canApproveInProject($project);
    }

    public function confirmDelete(int $projectId): void
    {
        $project = $this->projects->firstWhere('id', $projectId);
        abort_unless($project, 404);
        abort_unless($this->canDeleteProject($project), 403);

        $this->confirmingDeleteId = $projectId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
        $this->deleteConfirmationName = '';
        $this->resetErrorBag();
    }

    public function deleteProject(int $projectId): void
    {
        $project = $this->projects->firstWhere('id', $projectId);
        abort_unless($project, 404);
        abort_unless($this->canDeleteProject($project), 403);

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
        $this->confirmingDeleteId = null;
        $this->deleteConfirmationName = '';
        unset($this->projects);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Projects') }}</flux:heading>

    <div class="flex flex-col gap-3">
        @forelse ($this->projects as $project)
            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="solid">{{ $project->name }}</flux:badge>
                    <flux:badge>{{ $project->team->workspace->name }} / {{ $project->team->name }}</flux:badge>
                </div>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ $project->stories_count }} {{ __('stories') }}
                    &middot; {{ $project->features_count }} {{ __('features') }}
                    &middot; {{ $project->repos_count }} {{ __('repos') }}
                </flux:text>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('projects.show', $project) }}" wire:navigate>
                        <flux:button size="sm" variant="primary">{{ __('Open project') }}</flux:button>
                    </a>
                    @if ($this->canDeleteProject($project))
                        <flux:modal.trigger name="delete-project-index-modal">
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete({{ $project->id }})">{{ __('Delete') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No projects in your teams yet.') }}</flux:text>
        @endforelse
    </div>

    @php($confirmingProject = $confirmingDeleteId ? $this->projects->firstWhere('id', $confirmingDeleteId) : null)
    <flux:modal name="delete-project-index-modal" class="md:w-96" @close="$wire.cancelDelete()">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Delete project?') }}</flux:heading>
            <flux:text>
                @if ($confirmingProject)
                    {{ __('This permanently removes :name, including its features, stories, tasks, subtasks, approvals, and repo attachments. This cannot be undone.', ['name' => $confirmingProject->name]) }}
                @else
                    {{ __('This permanently removes the project and all of its data. This cannot be undone.') }}
                @endif
            </flux:text>
            <flux:input
                wire:model="deleteConfirmationName"
                :label="__('Type the project name to confirm')"
                :placeholder="$confirmingProject?->name ?? ''"
                required
            />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost" wire:click="cancelDelete">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" icon="trash" wire:click="deleteProject({{ $confirmingDeleteId ?? 0 }})">{{ __('Delete project') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
