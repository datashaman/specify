<?php

use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Stories')] class extends Component {
    use WithPagination;

    public int $project_id;

    #[Url(as: 'status')]
    public ?string $status = null;

    public function mount(int $project): void
    {
        $user = Auth::user();
        abort_unless(in_array((int) $project, $user->accessibleProjectIds(), true), 404);
        $this->project_id = (int) $project;
        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function stories()
    {
        $projectIds = Auth::user()->scopedProjectIds();

        return Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->with(['feature.project', 'creator', 'tasks', 'currentPlan:id,story_id,version,name,status'])
            ->latest('updated_at')
            ->paginate(25);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Stories') }}</flux:heading>
        <a href="{{ route('stories.create', ['project' => $this->project_id]) }}" wire:navigate>
            <flux:button variant="primary">{{ __('+ New story') }}</flux:button>
        </a>
    </div>

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="status" :placeholder="__('All statuses')">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (StoryStatus::cases() as $s)
                <flux:select.option value="{{ $s->value }}">{{ $s->value }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($this->stories as $story)
            <x-story.summary-card
                :story="$story"
                :href="route('stories.show', ['project' => $story->feature->project_id, 'story' => $story->id])"
            />
        @empty
            <flux:text class="text-zinc-500">{{ __('No stories found.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->stories->links() }}
</div>
