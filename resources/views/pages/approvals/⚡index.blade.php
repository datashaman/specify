<?php

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Story;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Approvals')] class extends Component {
    public int $project_id;

    public array $notes = [];

    #[Url(as: 'queue')]
    public string $queue = 'all';

    public function mount(int $project): void
    {
        $user = Auth::user();
        abort_unless(in_array((int) $project, $user->accessibleProjectIds(), true), 404);
        abort_unless($user->canApproveInProject(Project::findOrFail($project)), 403);

        $this->project_id = (int) $project;

        if ((int) $user->current_project_id !== $this->project_id) {
            $user->forceFill(['current_project_id' => $this->project_id])->save();
        }
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->project_id);
    }

    #[Computed]
    public function pendingStories()
    {
        return Story::query()
            ->where('status', StoryStatus::PendingApproval)
            ->whereHas('feature', fn ($q) => $q->where('project_id', $this->project_id))
            ->with(['feature.project', 'acceptanceCriteria', 'creator', 'approvals.approver'])
            ->latest('updated_at')
            ->get();
    }

    #[Computed]
    public function pendingPlans()
    {
        return Plan::query()
            ->where('status', PlanStatus::PendingApproval)
            ->whereHas('story', fn ($q) => $q->whereColumn('stories.current_plan_id', 'plans.id'))
            ->whereHas('story.feature', fn ($q) => $q->where('project_id', $this->project_id))
            ->with(['story.feature.project', 'story.creator', 'approvals.approver', 'tasks.subtasks'])
            ->latest('updated_at')
            ->get();
    }

    public function decideStory(int $id, string $decision): void
    {
        $user = Auth::user();
        $service = app(ApprovalService::class);
        $note = $this->notes['story:'.$id] ?? null;

        $service->recordDecision(
            $this->authorizedStory($id),
            $user,
            ApprovalDecision::from($decision),
            $note,
        );

        unset($this->notes['story:'.$id]);
        unset($this->pendingStories);
    }

    public function decidePlan(int $id, string $decision): void
    {
        $user = Auth::user();
        $service = app(ApprovalService::class);
        $note = $this->notes['plan:'.$id] ?? null;

        $service->recordPlanDecision(
            $this->authorizedPlan($id),
            $user,
            ApprovalDecision::from($decision),
            $note,
        );

        unset($this->notes['plan:'.$id]);
        unset($this->pendingPlans);
    }

    private function authorizedStory(int $id): Story
    {
        $story = Story::query()
            ->whereHas('feature', fn ($q) => $q->where('project_id', $this->project_id))
            ->with('feature.project')
            ->findOrFail($id);

        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        return $story;
    }

    private function authorizedPlan(int $id): Plan
    {
        $plan = Plan::query()
            ->whereHas('story', fn ($q) => $q->whereColumn('stories.current_plan_id', 'plans.id'))
            ->whereHas('story.feature', fn ($q) => $q->where('project_id', $this->project_id))
            ->with('story.feature.project')
            ->findOrFail($id);

        abort_unless(Auth::user()->canApproveInProject($plan->story->feature->project), 403);

        return $plan;
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Approvals') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">{{ $this->project->name }} · {{ __('contract and execution gates') }}</flux:text>
        </div>
        <a href="{{ route('plans.index', ['project' => $this->project_id]) }}" wire:navigate>
            <flux:button>{{ __('Open plans') }}</flux:button>
        </a>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach ([
            'all' => __('All queues'),
            'stories' => __('Story contracts'),
            'plans' => __('Current plans'),
        ] as $value => $label)
            <flux:button :variant="$queue === $value ? 'primary' : 'ghost'" wire:click="$set('queue', '{{ $value }}')">{{ $label }}</flux:button>
        @endforeach
    </div>

    <div class="grid gap-8 xl:grid-cols-2">
        @if (in_array($queue, ['all', 'stories'], true))
            <section class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Story contracts pending approval') }}</flux:heading>
                @forelse ($this->pendingStories as $story)
                    @php
                        $policy = $story->effectivePolicy();
                        $revisionApprovals = $story->approvals->where('story_revision', $story->revision ?? 1);
                        $effective = [];
                        foreach ($revisionApprovals->sortBy('created_at') as $a) {
                            $key = (int) $a->approver_id;
                            if ($a->decision === ApprovalDecision::Approve) {
                                $effective[$key] = $a;
                            } elseif ($a->decision === ApprovalDecision::Revoke) {
                                unset($effective[$key]);
                            }
                        }
                        $userApproved = isset($effective[auth()->id()]);
                    @endphp
                    <x-story.summary-card :story="$story" :href="route('stories.show', ['project' => $story->feature->project_id, 'story' => $story->id])" card-class="">
                        <x-slot:meta>
                            <div class="flex items-center gap-2">
                                <flux:badge variant="solid">{{ __('story') }}</flux:badge>
                                <flux:badge>{{ __('rev') }} {{ $story->revision }}</flux:badge>
                                <flux:badge>{{ count($effective) }}/{{ $policy->required_approvals }} {{ __('approvals') }}</flux:badge>
                                <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at->diffForHumans() }}</flux:text>
                            </div>
                        </x-slot:meta>
                        <flux:textarea class="mt-3" wire:model.defer="notes.story:{{ $story->id }}" :label="__('Story decision notes (optional)')" />
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($userApproved)
                                <flux:button wire:click="decideStory({{ $story->id }}, 'revoke')">{{ __('Revoke approval') }}</flux:button>
                            @else
                                <flux:button variant="primary" wire:click="decideStory({{ $story->id }}, 'approve')">{{ __('Approve story contract') }}</flux:button>
                            @endif
                            <flux:button wire:click="decideStory({{ $story->id }}, 'changes_requested')">{{ __('Request story changes') }}</flux:button>
                            <flux:button variant="danger" wire:click="decideStory({{ $story->id }}, 'reject')">{{ __('Reject story contract') }}</flux:button>
                        </div>
                    </x-story.summary-card>
                @empty
                    <flux:text class="text-zinc-500">{{ __('No story contracts pending approval.') }}</flux:text>
                @endforelse
            </section>
        @endif

        @if (in_array($queue, ['all', 'plans'], true))
            <section class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Current plans pending approval') }}</flux:heading>
                @forelse ($this->pendingPlans as $plan)
                    @php
                        $policy = $plan->effectivePolicy();
                        $revisionApprovals = $plan->approvals->where('plan_revision', $plan->revision ?? 1);
                        $effective = [];
                        foreach ($revisionApprovals->sortBy('created_at') as $a) {
                            $key = (int) $a->approver_id;
                            if ($a->decision === ApprovalDecision::Approve) {
                                $effective[$key] = $a;
                            } elseif ($a->decision === ApprovalDecision::Revoke) {
                                unset($effective[$key]);
                            }
                        }
                        $userApproved = isset($effective[auth()->id()]);
                        $taskCount = $plan->tasks->count();
                        $subtaskCount = $plan->tasks->sum(fn ($task) => $task->subtasks->count());
                    @endphp
                    <a href="{{ route('stories.show', ['project' => $plan->story->feature->project_id, 'story' => $plan->story->id]) }}" wire:navigate>
                        <flux:card class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <div class="flex items-center gap-2">
                                <flux:badge variant="solid">{{ __('plan') }}</flux:badge>
                                <flux:badge>{{ __('v') }}{{ $plan->version }}</flux:badge>
                                <flux:badge>{{ __('rev') }} {{ $plan->revision }}</flux:badge>
                                <flux:badge>{{ count($effective) }}/{{ $policy->required_approvals }} {{ __('approvals') }}</flux:badge>
                                <flux:badge>{{ $taskCount }} {{ __('tasks') }} · {{ $subtaskCount }} {{ __('subtasks') }}</flux:badge>
                                <flux:text class="ml-auto text-xs text-zinc-500">{{ $plan->updated_at->diffForHumans() }}</flux:text>
                            </div>
                            <div class="mt-3">
                                <flux:heading>{{ $plan->name ?? __('Current plan') }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500">{{ $plan->story->feature->name }} · {{ $plan->story->name }}</flux:text>
                            </div>
                            <flux:textarea class="mt-3" wire:model.defer="notes.plan:{{ $plan->id }}" :label="__('Plan decision notes (optional)')" />
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($userApproved)
                                    <flux:button wire:click.prevent="decidePlan({{ $plan->id }}, 'revoke')">{{ __('Revoke approval') }}</flux:button>
                                @else
                                    <flux:button variant="primary" wire:click.prevent="decidePlan({{ $plan->id }}, 'approve')">{{ __('Approve current plan') }}</flux:button>
                                @endif
                                <flux:button wire:click.prevent="decidePlan({{ $plan->id }}, 'changes_requested')">{{ __('Request plan changes') }}</flux:button>
                                <flux:button variant="danger" wire:click.prevent="decidePlan({{ $plan->id }}, 'reject')">{{ __('Reject current plan') }}</flux:button>
                            </div>
                        </flux:card>
                    </a>
                @empty
                    <flux:text class="text-zinc-500">{{ __('No current plans pending approval.') }}</flux:text>
                @endforelse
            </section>
        @endif
    </div>
</div>
