<?php

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
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
                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sm underline">{{ __('Open project') }}</a>
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No projects in your teams yet.') }}</flux:text>
        @endforelse
    </div>
</div>
