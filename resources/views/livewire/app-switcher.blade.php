<?php

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function user()
    {
        return Auth::user();
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
        $this->redirect(request()->header('Referer') ?? route('dashboard'), navigate: true);
    }
}; ?>

<flux:sidebar.nav>
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
        </flux:menu>
    </flux:dropdown>
</flux:sidebar.nav>
