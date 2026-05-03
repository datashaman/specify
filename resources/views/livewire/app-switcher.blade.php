<?php

use App\Enums\ProjectStatus;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Validate('required|string|max:255')]
    public string $newProjectName = '';

    #[Validate('nullable|string|max:1000')]
    public string $newProjectDescription = '';

    #[Computed]
    public function user()
    {
        return Auth::user();
    }

    #[Computed]
    public function canCreateProject(): bool
    {
        $teamId = $this->user->current_team_id;
        if (! $teamId) {
            return false;
        }
        $role = $this->user->roleInTeam($teamId);

        return in_array($role, [TeamRole::Owner, TeamRole::Admin], true);
    }

    #[Computed]
    public function workspaces()
    {
        return $this->user->accessibleWorkspaces();
    }

    #[Computed]
    public function projects()
    {
        return $this->user->accessibleProjectsInCurrentWorkspace();
    }

    #[Computed]
    public function currentWorkspace(): ?Workspace
    {
        return $this->user->currentWorkspace();
    }

    #[Computed]
    public function currentProject(): ?Project
    {
        return $this->user->current_project_id ? Project::find($this->user->current_project_id) : null;
    }

    public function switchWorkspace(int $workspaceId): void
    {
        $workspace = $this->user->accessibleWorkspaces()->firstWhere('id', $workspaceId);
        abort_unless($workspace, 403);

        $this->user->switchWorkspace($workspace);
        $this->redirect(request()->header('Referer') ?? route('dashboard'), navigate: true);
    }

    public function switchProject(?int $projectId): void
    {
        $project = $projectId
            ? Project::query()->whereIn('id', $this->user->accessibleProjectIds())->find($projectId)
            : null;

        if ($projectId !== null) {
            abort_unless($project, 403);
        }

        $this->user->switchProject($project);

        $target = $project
            ? route('projects.show', $project)
            : route('projects.index');

        $this->redirect($target, navigate: true);
    }

    public function createProject(): void
    {
        abort_unless($this->canCreateProject, 403);

        $teamId = $this->user->current_team_id;
        $this->validate();

        $project = Project::create([
            'team_id' => $teamId,
            'created_by_id' => $this->user->id,
            'name' => $this->newProjectName,
            'description' => $this->newProjectDescription ?: null,
            'status' => ProjectStatus::Active,
        ]);

        $this->user->switchProject($project);
        $this->reset(['newProjectName', 'newProjectDescription']);

        $this->redirectRoute('projects.show', $project, navigate: true);
    }
}; ?>

<div class="flex flex-col">
    @if ($this->workspaces->count() > 1)
        <flux:dropdown position="bottom" align="start">
            <flux:sidebar.item icon="building-office" icon-trailing="chevrons-up-down">
                {{ $this->currentWorkspace?->name ?? __('Select workspace') }}
            </flux:sidebar.item>
            <flux:menu>
                <flux:menu.radio.group>
                    @foreach ($this->workspaces as $ws)
                        <flux:menu.radio
                            :checked="$this->currentWorkspace?->id === $ws->id"
                            wire:click="switchWorkspace({{ $ws->id }})"
                        >{{ $ws->name }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
    @endif

    <flux:dropdown position="bottom" align="start">
        <flux:sidebar.item icon="folder" icon-trailing="chevrons-up-down">
            {{ $this->currentProject?->name ?? __('All projects') }}
        </flux:sidebar.item>
        <flux:menu>
            <flux:menu.radio.group>
                <flux:menu.radio
                    :checked="$this->currentProject === null"
                    wire:click="switchProject(null)"
                >{{ __('All projects') }}</flux:menu.radio>
                @foreach ($this->projects as $project)
                    <flux:menu.radio
                        :checked="$this->currentProject?->id === $project->id"
                        wire:click="switchProject({{ $project->id }})"
                    >{{ $project->name }}</flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>

            @if ($this->canCreateProject)
                <flux:menu.separator />
                <flux:modal.trigger name="new-project-modal">
                    <flux:menu.item icon="plus">{{ __('New project…') }}</flux:menu.item>
                </flux:modal.trigger>
            @endif
        </flux:menu>
    </flux:dropdown>

    @if ($this->canCreateProject)
        <flux:modal name="new-project-modal" class="md:w-96">
            <form wire:submit.prevent="createProject" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('New project') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Created in :workspace. You can manage repos and features after creation.', ['workspace' => $this->currentWorkspace?->name ?? __('your workspace')]) }}</flux:text>
                </div>
                <flux:input wire:model="newProjectName" :label="__('Name')" required />
                <flux:textarea wire:model="newProjectDescription" :label="__('Description (optional)')" rows="2" />
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
