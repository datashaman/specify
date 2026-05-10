<?php

use App\Enums\ContextItemType;
use App\Models\ContextItem;
use App\Models\Story;
use App\Services\Context\AssetUploader;
use App\Services\Context\ContextItemWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public int $story_id;

    #[Validate('required|in:text,link,file')]
    public string $newType = 'text';

    #[Validate('required|string|max:255')]
    public string $newTitle = '';

    #[Validate('nullable|string|max:10000')]
    public string $newBody = '';

    #[Validate('nullable|url')]
    public string $newUrl = '';

    public mixed $newFile = null;

    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $editTitle = '';

    #[Validate('nullable|string|max:10000')]
    public string $editBody = '';

    public function mount(int $storyId): void
    {
        $this->story_id = $storyId;
        $this->ensureMember();
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
    public function items()
    {
        $story = $this->story;

        return $story === null ? collect() : $story->ownedContextItems()->orderByDesc('id')->get();
    }

    public function create(): void
    {
        $this->ensureMember();

        $rules = ['newType' => 'required|in:text,link,file', 'newTitle' => 'required|string|max:255'];
        match ($this->newType) {
            'text' => $rules['newBody'] = 'required|string|max:10000',
            'link' => $rules['newUrl'] = 'required|url',
            'file' => $rules['newFile'] = 'required|file',
        };
        $this->validate($rules);

        $story = $this->story;
        $project = $story->feature->project;
        $actor = Auth::user();

        if ($this->newType === 'file') {
            /** @var UploadedFile $file */
            $file = $this->newFile;
            app(AssetUploader::class)->store($file, $project, $story, $actor);
        } else {
            $type = ContextItemType::from($this->newType);
            $metadata = $type === ContextItemType::Text
                ? ['body' => $this->newBody]
                : ['url' => $this->newUrl];

            app(ContextItemWriter::class)->createStoryItem($story, [
                'type' => $type,
                'title' => $this->newTitle,
                'metadata' => $metadata,
            ], $actor);
        }

        $this->reset(['newTitle', 'newBody', 'newUrl', 'newFile']);
        $this->newType = 'text';
        unset($this->items);
    }

    public function startEdit(int $itemId): void
    {
        $this->ensureMember();
        $item = $this->itemFor($itemId);

        $this->editingId = $item->id;
        $this->editTitle = (string) $item->title;
        $this->editBody = (string) ($item->metadata['body'] ?? '');
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editTitle = '';
        $this->editBody = '';
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->ensureMember();
        if ($this->editingId === null) {
            return;
        }

        $item = $this->itemFor($this->editingId);

        $rules = ['editTitle' => 'required|string|max:255'];
        if ($item->type !== ContextItemType::File) {
            $rules['editBody'] = 'nullable|string|max:10000';
        }
        $this->validate($rules);

        $changes = ['title' => $this->editTitle];
        if ($item->type === ContextItemType::Text) {
            $changes['metadata'] = ['body' => $this->editBody];
        }

        app(ContextItemWriter::class)->update($item, $changes, Auth::user());

        $this->cancelEdit();
        unset($this->items);
    }

    public function delete(int $itemId): void
    {
        $this->ensureMember();
        $item = $this->itemFor($itemId);

        app(ContextItemWriter::class)->delete($item, Auth::user());

        unset($this->items);
    }

    private function itemFor(int $itemId): ContextItem
    {
        $story = $this->story;
        abort_unless($story, 404);

        $item = ContextItem::query()
            ->where('story_id', $story->id)
            ->find($itemId);
        abort_unless($item, 404);

        return $item;
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

<section data-section="story-assets" class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ __('Story-only assets') }}</flux:heading>
    </div>
    <flux:callout color="amber" icon="exclamation-triangle">
        <flux:callout.text>
            {{ __('Adding, editing, or removing story-scoped assets reopens this Story for approval.') }}
        </flux:callout.text>
    </flux:callout>

    <div data-section="story-assets-create" class="flex flex-col gap-2 rounded-md border border-zinc-200 p-4 dark:border-zinc-800">
        <flux:heading size="sm">{{ __('Add asset') }}</flux:heading>
        <flux:select wire:model.live="newType" :label="__('Type')">
            <flux:select.option value="text">{{ __('Text note') }}</flux:select.option>
            <flux:select.option value="link">{{ __('Link') }}</flux:select.option>
            <flux:select.option value="file">{{ __('File') }}</flux:select.option>
        </flux:select>
        <flux:input wire:model="newTitle" :label="__('Title')" required />

        @if ($newType === 'text')
            <flux:textarea wire:model="newBody" :label="__('Body')" rows="4" required />
        @elseif ($newType === 'link')
            <flux:input wire:model="newUrl" :label="__('URL')" type="url" required />
        @elseif ($newType === 'file')
            <flux:field>
                <flux:label>{{ __('File') }}</flux:label>
                <input type="file" wire:model="newFile" class="text-sm" />
                <flux:error name="newFile" />
            </flux:field>
        @endif

        <div>
            <flux:button wire:click="create" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </div>

    <div data-section="story-assets-list" class="flex flex-col gap-2">
        @forelse ($this->items as $item)
            <div data-asset-id="{{ $item->id }}" class="flex flex-col gap-2 rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
                @if ($editingId === $item->id)
                    <flux:input wire:model="editTitle" :label="__('Title')" required />
                    @if ($item->type === \App\Enums\ContextItemType::Text)
                        <flux:textarea wire:model="editBody" :label="__('Body')" rows="4" />
                    @endif
                    <div class="flex gap-2">
                        <flux:button wire:click="saveEdit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                        <flux:button wire:click="cancelEdit" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                    </div>
                @else
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex flex-col">
                            <flux:text class="font-medium">{{ $item->title }}</flux:text>
                            <flux:text size="xs" class="text-zinc-500">{{ $item->type->value }}</flux:text>
                        </div>
                        <div class="flex gap-2">
                            <flux:button wire:click="startEdit({{ $item->id }})" size="xs" icon="pencil-square">{{ __('Edit') }}</flux:button>
                            <flux:button wire:click="delete({{ $item->id }})" size="xs" variant="danger" icon="trash">{{ __('Delete') }}</flux:button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <flux:text class="text-zinc-500">{{ __('No story-only assets yet.') }}</flux:text>
        @endforelse
    </div>
</section>
