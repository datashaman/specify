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

<div class="flex flex-col gap-1 px-2 py-2">
    @if ($this->workspaces->count() > 1)
        <flux:dropdown>
            <flux:button variant="ghost" size="sm" icon-trailing="chevron-down" class="w-full justify-between">
                <div class="flex flex-col items-start">
                    <span class="text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Workspace') }}</span>
                    <span class="truncate text-xs font-medium">{{ $this->currentWorkspace?->name ?? __('None') }}</span>
                </div>
            </flux:button>
            <flux:menu>
                @foreach ($this->workspaces as $ws)
                    <flux:menu.item wire:click="switchWorkspace({{ $ws->id }})">
                        {{ $ws->name }}
                        @if ($this->currentWorkspace?->id === $ws->id)
                            <flux:badge size="sm" class="ml-2">{{ __('current') }}</flux:badge>
                        @endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif

    <flux:dropdown>
        <flux:button variant="ghost" size="sm" icon-trailing="chevron-down" class="w-full justify-between">
            <div class="flex flex-col items-start">
                <span class="text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Project') }}</span>
                <span class="truncate text-xs font-medium">{{ $this->currentProject?->name ?? __('All projects') }}</span>
            </div>
        </flux:button>
        <flux:menu>
            <flux:menu.item wire:click="switchProject(null)">
                {{ __('All projects') }}
                @if ($this->currentProject === null)
                    <flux:badge size="sm" class="ml-2">{{ __('current') }}</flux:badge>
                @endif
            </flux:menu.item>
            <flux:menu.separator />
            @forelse ($this->projects as $project)
                <flux:menu.item wire:click="switchProject({{ $project->id }})">
                    {{ $project->name }}
                    @if ($this->currentProject?->id === $project->id)
                        <flux:badge size="sm" class="ml-2">{{ __('current') }}</flux:badge>
                    @endif
                </flux:menu.item>
            @empty
                <flux:menu.item disabled>{{ __('No projects in this workspace') }}</flux:menu.item>
            @endforelse
        </flux:menu>
    </flux:dropdown>
</div>
