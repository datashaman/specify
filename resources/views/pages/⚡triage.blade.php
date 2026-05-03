<?php

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\Plan;
use App\Models\Story;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Triage')] class extends Component {
    public array $notes = [];

    #[Computed]
    public function projectIds()
    {
        return Auth::user()->scopedProjectIds();
    }

    #[Computed]
    public function pendingStories()
    {
        return Story::query()
            ->where('status', StoryStatus::PendingApproval)
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $this->projectIds))
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
            ->whereHas('story.feature', fn ($q) => $q->whereIn('project_id', $this->projectIds))
            ->with(['story.feature.project', 'story.creator', 'approvals.approver', 'tasks.subtasks'])
            ->latest('updated_at')
            ->get();
    }

    public function decide(int $id, string $decision): void
    {
        $user = Auth::user();
        $service = app(ApprovalService::class);
        $note = $this->notes['story:'.$id] ?? null;
        $decisionEnum = ApprovalDecision::from($decision);
        $accessible = $user->accessibleProjectIds();

        $service->recordDecision(
            $this->authorizedStory($id, $accessible, $user),
            $user,
            $decisionEnum,
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
        $decisionEnum = ApprovalDecision::from($decision);
        $accessible = $user->accessibleProjectIds();

        $service->recordPlanDecision(
            $this->authorizedPlan($id, $accessible, $user),
            $user,
            $decisionEnum,
            $note,
        );

        unset($this->notes['plan:'.$id]);
        unset($this->pendingPlans);
    }

    private function authorizedStory(int $id, array $projectIds, $user): Story
    {
        $story = Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with('feature.project')
            ->findOrFail($id);

        abort_unless($user->canApproveInProject($story->feature->project), 403);

        return $story;
    }

    private function authorizedPlan(int $id, array $projectIds, $user): Plan
    {
        $plan = Plan::query()
            ->whereHas('story', fn ($q) => $q->whereColumn('stories.current_plan_id', 'plans.id'))
            ->whereHas('story.feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with('story.feature.project')
            ->findOrFail($id);

        abort_unless($user->canApproveInProject($plan->story->feature->project), 403);

        return $plan;
    }
}; ?>

<div class="flex flex-col gap-8 p-6">
    <flux:heading size="xl">{{ __('Triage') }}</flux:heading>

    @php
        $user = auth()->user();
    @endphp

    <div class="grid gap-8 xl:grid-cols-2">
    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Story contracts pending approval') }}</flux:heading>
        @forelse ($this->pendingStories as $story)
            @php
                $project = $story->feature->project;
                $policy = $story->effectivePolicy();
                $revisionApprovals = $story->approvals->where('story_revision', $story->revision ?? 1);
                $effective = [];
                foreach ($revisionApprovals->sortBy('created_at') as $a) {
                    $key = (int) $a->approver_id;
                    if ($a->decision === \App\Enums\ApprovalDecision::Approve) {
                        $effective[$key] = $a;
                    } elseif ($a->decision === \App\Enums\ApprovalDecision::Revoke) {
                        unset($effective[$key]);
                    }
                }
                $approveCount = count($effective);
                $required = $policy->required_approvals;
                $userApproved = isset($effective[$user->id]);
                $canApprove = $user->canApproveInProject($project);
                $isAuthor = $story->created_by_id === $user->id;
                $blockedBySelfApproval = $isAuthor && ! $policy->allow_self_approval;
            @endphp
            <x-story.summary-card
                :story="$story"
                :href="route('stories.show', ['project' => $story->feature->project_id, 'story' => $story->id])"
                card-class=""
            >
                <x-slot:meta>
                    <div class="flex items-center gap-2">
                        <flux:badge variant="solid">{{ $project->name }}</flux:badge>
                        <flux:badge>rev {{ $story->revision }}</flux:badge>
                        <flux:badge>{{ $approveCount }}/{{ $required }} {{ __('approvals') }}</flux:badge>
                        @if ($story->creator)
                            <flux:badge>{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                        @endif
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at->diffForHumans() }}</flux:text>
                    </div>
                </x-slot:meta>

                @if ($story->acceptanceCriteria->isNotEmpty())
                    <ul class="mt-3 list-disc pl-5 text-sm">
                        @foreach ($story->acceptanceCriteria as $ac)
                            <li>{{ $ac->statement }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($effective !== [])
                    <flux:text class="mt-3 text-xs text-zinc-500">
                        {{ __('Approved by') }}:
                        {{ collect($effective)->map(fn ($a) => $a->approver?->name ?? 'unknown')->implode(', ') }}
                    </flux:text>
                @endif

                @if ($blockedBySelfApproval)
                    <flux:text class="mt-3 text-xs text-amber-600">
                        {{ __('You authored this story; the policy disallows self-approval.') }}
                    </flux:text>
                @elseif (! $canApprove)
                    <flux:text class="mt-3 text-xs text-zinc-500">
                        {{ __('Your role does not permit approval decisions on this project.') }}
                    </flux:text>
                @endif

                @if ($canApprove && ! $blockedBySelfApproval)
                    <flux:textarea
                        class="mt-3"
                        wire:model.defer="notes.story:{{ $story->id }}"
                        :label="__('Decision notes (optional)')"
                    />
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($userApproved)
                            <flux:button wire:click="decide({{ $story->id }}, 'revoke')">{{ __('Revoke approval') }}</flux:button>
                        @else
                            <flux:button variant="primary" wire:click="decide({{ $story->id }}, 'approve')">{{ __('Approve') }}</flux:button>
                        @endif
                        <flux:button variant="danger" wire:click="decide({{ $story->id }}, 'reject')">{{ __('Reject') }}</flux:button>
                        <flux:button wire:click="decide({{ $story->id }}, 'changes_requested')">{{ __('Request story changes') }}</flux:button>
                    </div>
                @endif
            </x-story.summary-card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No stories pending approval.') }}</flux:text>
        @endforelse
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Current plans pending approval') }}</flux:heading>
        @forelse ($this->pendingPlans as $plan)
            @php
                $project = $plan->story->feature->project;
                $policy = $plan->effectivePolicy();
                $revisionApprovals = $plan->approvals->where('plan_revision', $plan->revision ?? 1);
                $effective = [];
                foreach ($revisionApprovals->sortBy('created_at') as $a) {
                    $key = (int) $a->approver_id;
                    if ($a->decision === \App\Enums\ApprovalDecision::Approve) {
                        $effective[$key] = $a;
                    } elseif ($a->decision === \App\Enums\ApprovalDecision::Revoke) {
                        unset($effective[$key]);
                    }
                }
                $approveCount = count($effective);
                $required = $policy->required_approvals;
                $userApproved = isset($effective[$user->id]);
                $canApprove = $user->canApproveInProject($project);
                $isAuthor = $plan->story->created_by_id === $user->id;
                $blockedBySelfApproval = $isAuthor && ! $policy->allow_self_approval;
                $taskCount = $plan->tasks->count();
                $subtaskCount = $plan->tasks->sum(fn ($task) => $task->subtasks->count());
            @endphp
            <x-story.summary-card
                :story="$plan->story"
                :href="route('stories.show', ['project' => $plan->story->feature->project_id, 'story' => $plan->story->id])"
                card-class=""
            >
                <x-slot:meta>
                    <div class="flex items-center gap-2">
                        <flux:badge variant="solid">{{ $project->name }}</flux:badge>
                        <flux:badge>{{ __('plan') }} v{{ $plan->version }}</flux:badge>
                        <flux:badge>{{ __('rev') }} {{ $plan->revision }}</flux:badge>
                        <flux:badge>{{ $approveCount }}/{{ $required }} {{ __('approvals') }}</flux:badge>
                        <flux:badge>{{ $taskCount }} {{ __('tasks') }} · {{ $subtaskCount }} {{ __('subtasks') }}</flux:badge>
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $plan->updated_at->diffForHumans() }}</flux:text>
                    </div>
                </x-slot:meta>

                <div class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                    <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $plan->name ?? __('Current plan') }}</div>
                    @if ($plan->summary)
                        <div class="mt-1">{{ $plan->summary }}</div>
                    @else
                        <div class="mt-1">{{ __('Execution plan for') }} {{ $plan->story->name }}</div>
                    @endif
                </div>

                @if ($effective !== [])
                    <flux:text class="mt-3 text-xs text-zinc-500">
                        {{ __('Approved by') }}:
                        {{ collect($effective)->map(fn ($a) => $a->approver?->name ?? 'unknown')->implode(', ') }}
                    </flux:text>
                @endif

                @if ($blockedBySelfApproval)
                    <flux:text class="mt-3 text-xs text-amber-600">
                        {{ __('You authored this story; the policy disallows self-approval for its current plan.') }}
                    </flux:text>
                @elseif (! $canApprove)
                    <flux:text class="mt-3 text-xs text-zinc-500">
                        {{ __('Your role does not permit approval decisions on this project.') }}
                    </flux:text>
                @endif

                @if ($canApprove && ! $blockedBySelfApproval)
                    <flux:textarea
                        class="mt-3"
                        wire:model.defer="notes.plan:{{ $plan->id }}"
                        :label="__('Plan decision notes (optional)')"
                    />
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($userApproved)
                            <flux:button wire:click="decidePlan({{ $plan->id }}, 'revoke')">{{ __('Revoke approval') }}</flux:button>
                        @else
                            <flux:button variant="primary" wire:click="decidePlan({{ $plan->id }}, 'approve')">{{ __('Approve current plan') }}</flux:button>
                        @endif
                        <flux:button variant="danger" wire:click="decidePlan({{ $plan->id }}, 'reject')">{{ __('Reject current plan') }}</flux:button>
                        <flux:button wire:click="decidePlan({{ $plan->id }}, 'changes_requested')">{{ __('Request plan changes') }}</flux:button>
                    </div>
                @endif
            </x-story.summary-card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No current plans pending approval.') }}</flux:text>
        @endforelse
    </section>
    </div>
</div>
