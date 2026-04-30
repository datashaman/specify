<?php

use App\Models\ContextItem;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Project context')] class extends Component {
    public int $project_id;

    public function mount(int $project): void
    {
        $this->project_id = $project;

        abort_unless($this->project, 404);
        abort_unless(Auth::user()->canApproveInProject($this->project), 403);
    }

    #[Computed]
    public function project(): ?Project
    {
        return Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->with('team.workspace')
            ->find($this->project_id);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ContextItem>
     */
    #[Computed]
    public function contextItems()
    {
        return $this->project
            ? $this->project->contextItems()->orderBy('id')->get()
            : collect();
    }
};
?>

<div class="flex flex-col gap-6 p-6">
    @if (! $this->project)
        <flux:text class="text-zinc-500">{{ __('Project not found.') }}</flux:text>
    @else
        <div class="flex flex-col gap-3">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('projects.show', $this->project)" wire:navigate>
                    {{ $this->project->name }}
                </flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Context') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Project context') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500">
                    {{ $this->project->team->workspace->name }} / {{ $this->project->team->name }}
                </flux:text>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            @forelse ($this->contextItems as $contextItem)
                <flux:card wire:key="context-item-{{ $contextItem->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">{{ Str::headline($contextItem->type) }}</flux:badge>
                        <flux:text class="ml-auto text-xs text-zinc-500">#{{ $contextItem->id }}</flux:text>
                    </div>

                    <flux:heading class="mt-2">{{ $contextItem->title }}</flux:heading>

                    @if ($contextItem->description)
                        <flux:text class="mt-1">{{ $contextItem->description }}</flux:text>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No context items yet.') }}</flux:text>
            @endforelse
        </section>
    @endif
</div>
