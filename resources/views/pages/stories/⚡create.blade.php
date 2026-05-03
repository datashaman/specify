<?php

use App\Enums\StoryKind;
use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\Feature;
use App\Models\Story;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('New story')] class extends Component {
    #[Url(as: 'feature_id')]
    public ?int $feature_id = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $kind = 'user_story';

    public string $actor = '';

    public string $intent = '';

    public string $outcome = '';

    #[Validate('required|string')]
    public string $description = '';

    /** @var array<int,string> */
    public array $criteria = [''];

    public function mount(int $project): void
    {
        $user = Auth::user();
        abort_unless(in_array((int) $project, $user->accessibleProjectIds(), true), 404);
        if ((int) $user->current_project_id !== (int) $project) {
            $user->forceFill(['current_project_id' => (int) $project])->save();
        }

        if ($this->feature_id) {
            $feature = Feature::query()
                ->where('project_id', (int) $project)
                ->find($this->feature_id);
            if ($feature) {
                return;
            }
            $this->feature_id = null;
        }

        $this->feature_id = Feature::query()
            ->where('project_id', (int) $project)
            ->orderBy('id')
            ->first()?->id;
    }

    #[Computed]
    public function features()
    {
        $projectId = Auth::user()->current_project_id;

        return $projectId
            ? Feature::query()->where('project_id', $projectId)->orderBy('name')->get()
            : collect();
    }

    public function addCriterion(): void
    {
        $this->criteria[] = '';
    }

    public function removeCriterion(int $i): void
    {
        unset($this->criteria[$i]);
        $this->criteria = array_values($this->criteria);
        if ($this->criteria === []) {
            $this->criteria = [''];
        }
    }

    public function save(bool $submit = false): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'feature_id' => 'required|integer',
        ]);

        $accessible = Auth::user()->accessibleProjectIds();
        $feature = Feature::query()
            ->whereIn('project_id', $accessible)
            ->findOrFail($this->feature_id);

        DB::transaction(function () use ($feature, $submit) {
            $story = Story::create([
                'feature_id' => $feature->id,
                'created_by_id' => Auth::id(),
                'name' => $this->name,
                'kind' => StoryKind::from($this->kind),
                'actor' => $this->actor ?: null,
                'intent' => $this->intent ?: null,
                'outcome' => $this->outcome ?: null,
                'description' => $this->description,
                'status' => StoryStatus::Draft,
            ]);

            foreach (array_filter(array_map('trim', $this->criteria)) as $i => $statement) {
                AcceptanceCriterion::create([
                    'story_id' => $story->id,
                    'position' => $i,
                    'statement' => $statement,
                ]);
            }

            if ($submit) {
                $story->submitForApproval();
            }
        });

        $this->redirectRoute('features.show', [
            'project' => $feature->project_id,
            'feature' => $feature->id,
        ], navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('New story') }}</flux:heading>

    <form wire:submit.prevent="save(false)" class="flex flex-col gap-4">
        <flux:select wire:model="feature_id" :label="__('Feature')">
            @foreach ($this->features as $feature)
                <flux:select.option value="{{ $feature->id }}">{{ $feature->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:select wire:model="kind" :label="__('Story kind')">
            @foreach (StoryKind::cases() as $storyKind)
                <flux:select.option value="{{ $storyKind->value }}">{{ $storyKind->value }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="actor" :label="__('As a …')" />
        <flux:input wire:model="intent" :label="__('I want …')" />
        <flux:input wire:model="outcome" :label="__('So that …')" />
        <flux:textarea wire:model="description" :label="__('Description / context')" rows="4" required />

        <div class="flex flex-col gap-2">
            <flux:heading size="md">{{ __('Acceptance criteria') }}</flux:heading>
            @foreach ($criteria as $i => $statement)
                <div class="flex gap-2">
                    <flux:input class="flex-1" wire:model="criteria.{{ $i }}" :placeholder="__('Atomic rule statement')" />
                    <flux:button type="button" variant="ghost" wire:click="removeCriterion({{ $i }})">{{ __('Remove') }}</flux:button>
                </div>
            @endforeach
            <flux:button type="button" variant="ghost" wire:click="addCriterion">{{ __('+ Add criterion') }}</flux:button>
        </div>

        <div class="mt-4 flex gap-2">
            <flux:button type="submit" variant="ghost">{{ __('Save draft') }}</flux:button>
            <flux:button type="button" variant="primary" wire:click="save(true)">{{ __('Save & submit for approval') }}</flux:button>
        </div>
    </form>
</div>
