<?php

use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('New story')] class extends Component {
    public ?int $project_id = null;

    public ?int $feature_id = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string')]
    public string $description = '';

    /** @var array<int,string> */
    public array $criteria = [''];

    public function mount(): void
    {
        $project = Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->orderBy('id')
            ->first();
        $this->project_id = $project?->id;
        $this->feature_id = $project?->features()->orderBy('id')->first()?->id;
    }

    #[Computed]
    public function projects()
    {
        return Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->with('features')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function features()
    {
        return $this->project_id
            ? Feature::query()->where('project_id', $this->project_id)->orderBy('name')->get()
            : collect();
    }

    public function updatedProjectId(): void
    {
        $this->feature_id = $this->features->first()?->id;
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
                'description' => $this->description,
                'status' => StoryStatus::Draft,
            ]);

            foreach (array_filter(array_map('trim', $this->criteria)) as $i => $statement) {
                AcceptanceCriterion::create([
                    'story_id' => $story->id,
                    'position' => $i,
                    'criterion' => $statement,
                    'met' => false,
                ]);
            }

            if ($submit) {
                $story->submitForApproval();
            }
        });

        $this->redirectRoute('inbox', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('New story') }}</flux:heading>

    <form wire:submit.prevent="save(false)" class="flex flex-col gap-4">
        <flux:select wire:model.live="project_id" :label="__('Project')">
            @foreach ($this->projects as $project)
                <flux:select.option value="{{ $project->id }}">{{ $project->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="feature_id" :label="__('Feature')">
            @foreach ($this->features as $feature)
                <flux:select.option value="{{ $feature->id }}">{{ $feature->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:textarea wire:model="description" :label="__('Description')" rows="4" required />

        <div class="flex flex-col gap-2">
            <flux:heading size="md">{{ __('Acceptance criteria') }}</flux:heading>
            @foreach ($criteria as $i => $statement)
                <div class="flex gap-2">
                    <flux:input class="flex-1" wire:model="criteria.{{ $i }}" :placeholder="__('Criterion')" />
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
