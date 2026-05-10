<?php

use App\Models\Story;
use App\Services\Context\ContextItemSelector;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $story_id;

    /** @var array<int, int> Project-scoped item IDs the user has checked in the picker. */
    public array $selected = [];

    public function mount(int $storyId): void
    {
        $this->story_id = $storyId;
        $this->ensureMember();
        $this->selected = $this->currentProjectScopedIds();
    }

    #[Computed]
    public function story(): ?Story
    {
        $story = Story::query()->with('feature')->find($this->story_id);
        if ($story === null) {
            return null;
        }

        $projectId = (int) ($story->feature?->project_id ?? 0);
        if (! in_array($projectId, Auth::user()->accessibleProjectIds(), true)) {
            return null;
        }

        return $story;
    }

    #[Computed]
    public function available()
    {
        $story = $this->story;

        return $story === null ? collect() : $story->availableContextItems()->orderByDesc('id')->get();
    }

    public function save(): void
    {
        $this->ensureMember();

        $story = $this->story;
        abort_unless($story, 404);

        // Story-scoped items aren't toggleable via the picker; bulkSet
        // explicitly only manages project-scoped IDs. Filter before passing.
        $projectScopedIds = $this->available
            ->whereNull('story_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $desired = array_values(array_intersect(
            array_map('intval', $this->selected),
            $projectScopedIds,
        ));

        app(ContextItemSelector::class)->bulkSet($story, $desired, Auth::user());

        $this->selected = $this->currentProjectScopedIds();
        unset($this->available);
    }

    /**
     * @return list<int>
     */
    private function currentProjectScopedIds(): array
    {
        $story = $this->story;
        if ($story === null) {
            return [];
        }

        return $story->includedContextItems()
            ->whereNull('context_items.story_id')
            ->pluck('context_items.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function ensureMember(): void
    {
        $story = Story::query()->with('feature')->find($this->story_id);
        abort_unless($story, 404);

        $projectId = (int) ($story->feature?->project_id ?? 0);
        abort_unless(
            in_array($projectId, Auth::user()->accessibleProjectIds(), true),
            403,
        );
    }
}; ?>

<section data-section="story-context-picker" class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ __('AI context selection') }}</flux:heading>
    </div>
    <flux:callout color="amber" icon="exclamation-triangle">
        <flux:callout.text>
            {{ __('Toggling selection reopens this Story for approval. Save once you are done.') }}
        </flux:callout.text>
    </flux:callout>

    <div class="flex flex-col gap-2">
        @forelse ($this->available as $item)
            @php $isStoryScoped = $item->story_id !== null; @endphp
            <label data-asset-id="{{ $item->id }}" class="flex items-start gap-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
                @if ($isStoryScoped)
                    <input type="checkbox" checked disabled class="mt-1" />
                @else
                    <input type="checkbox" wire:model="selected" value="{{ $item->id }}" class="mt-1" />
                @endif
                <div class="flex flex-col">
                    <flux:text class="font-medium">{{ $item->title }}</flux:text>
                    <flux:text size="xs" class="text-zinc-500">
                        {{ $item->type->value }}{{ $isStoryScoped ? ' · '.__('story-scoped (auto-included)') : '' }}
                    </flux:text>
                </div>
            </label>
        @empty
            <flux:text class="text-zinc-500">{{ __('No context assets available for this project yet.') }}</flux:text>
        @endforelse
    </div>

    <div>
        <flux:button wire:click="save" variant="primary">{{ __('Save selection') }}</flux:button>
    </div>
</section>
