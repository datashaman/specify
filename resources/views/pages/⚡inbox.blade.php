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

new #[Title('Inbox')] class extends Component {
    public array $notes = [];

    #[Computed]
    public function pendingStories()
    {
        $projectIds = Auth::user()->accessibleProjectIds();

        return Story::query()
            ->where('status', StoryStatus::PendingApproval)
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with(['feature.project', 'acceptanceCriteria', 'creator', 'approvals.approver'])
            ->latest('updated_at')
            ->get();
    }

    #[Computed]
    public function pendingPlans()
    {
        $projectIds = Auth::user()->accessibleProjectIds();

        return Plan::query()
            ->where('status', PlanStatus::PendingApproval)
            ->whereHas('story.feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with(['story.feature.project', 'tasks.dependencies', 'approvals.approver'])
            ->latest('updated_at')
            ->get();
    }

    public function decide(string $kind, int $id, string $decision): void
    {
        $user = Auth::user();
        $service = app(ApprovalService::class);
        $note = $this->notes[$kind.':'.$id] ?? null;
        $decisionEnum = ApprovalDecision::from($decision);
        $accessible = $user->accessibleProjectIds();

        match ($kind) {
            'story' => $service->recordDecision(
                $this->authorizedStory($id, $accessible, $user),
                $user,
                $decisionEnum,
                $note,
            ),
            'plan' => $service->recordDecision(
                $this->authorizedPlan($id, $accessible, $user),
                $user,
                $decisionEnum,
                $note,
            ),
        };

        unset($this->notes[$kind.':'.$id]);
        unset($this->pendingStories, $this->pendingPlans);
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
            ->whereHas('story.feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with('story.feature.project')
            ->findOrFail($id);

        abort_unless($user->canApproveInProject($plan->story->feature->project), 403);

        return $plan;
    }
}; ?>

<div class="flex flex-col gap-8 p-6">
    <flux:heading size="xl">{{ __('Inbox') }}</flux:heading>

    @php
        $user = auth()->user();
    @endphp

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Stories pending approval') }}</flux:heading>
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
            <flux:card>
                <div class="flex items-center gap-2">
                    <flux:badge variant="solid">{{ $project->name }}</flux:badge>
                    <flux:badge>rev {{ $story->revision }}</flux:badge>
                    <flux:badge>{{ $approveCount }}/{{ $required }} {{ __('approvals') }}</flux:badge>
                    @if ($story->creator)
                        <flux:badge>{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                    @endif
                    <flux:text class="ml-auto text-xs text-zinc-500">{{ $story->updated_at->diffForHumans() }}</flux:text>
                </div>

                <flux:heading class="mt-2">{{ $story->name }}</flux:heading>
                <flux:text class="mt-1">{{ $story->description }}</flux:text>

                @if ($story->acceptanceCriteria->isNotEmpty())
                    <ul class="mt-3 list-disc pl-5 text-sm">
                        @foreach ($story->acceptanceCriteria as $ac)
                            <li>{{ $ac->criterion }}</li>
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
                        :placeholder="__('Notes (optional)')"
                    />
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($userApproved)
                            <flux:button wire:click="decide('story', {{ $story->id }}, 'revoke')">{{ __('Revoke approval') }}</flux:button>
                        @else
                            <flux:button variant="primary" wire:click="decide('story', {{ $story->id }}, 'approve')">{{ __('Approve') }}</flux:button>
                        @endif
                        <flux:button variant="danger" wire:click="decide('story', {{ $story->id }}, 'reject')">{{ __('Reject') }}</flux:button>
                        <flux:button wire:click="decide('story', {{ $story->id }}, 'changes_requested')">{{ __('Request changes') }}</flux:button>
                    </div>
                @endif
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No stories pending approval.') }}</flux:text>
        @endforelse
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Plans pending approval') }}</flux:heading>
        @forelse ($this->pendingPlans as $plan)
            @php
                $project = $plan->story->feature->project;
                $policy = $plan->effectivePolicy();
                $effective = [];
                foreach ($plan->approvals->sortBy('created_at') as $a) {
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
                $tasksByPosition = $plan->tasks->keyBy('position');
            @endphp
            <flux:card>
                <div class="flex items-center gap-2">
                    <flux:badge variant="solid">{{ $project->name }}</flux:badge>
                    <flux:badge>v{{ $plan->version }}</flux:badge>
                    <flux:badge>{{ $approveCount }}/{{ $required }} {{ __('approvals') }}</flux:badge>
                    <flux:text class="ml-auto text-xs text-zinc-500">{{ $plan->updated_at->diffForHumans() }}</flux:text>
                </div>

                <flux:heading class="mt-2">{{ $plan->story->name }}</flux:heading>
                @if ($plan->summary)
                    <flux:text class="mt-1">{{ $plan->summary }}</flux:text>
                @endif

                <table class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="w-10 py-1">#</th>
                            <th class="py-1">{{ __('Task') }}</th>
                            <th class="py-1">{{ __('Depends on') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plan->tasks as $task)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1 align-top text-zinc-500">{{ $task->position }}</td>
                                <td class="py-1 align-top">{{ $task->name }}</td>
                                <td class="py-1 align-top text-zinc-500">
                                    @php
                                        $deps = $task->dependencies->map(fn ($d) => '#'.$d->position.' '.$d->name);
                                    @endphp
                                    {{ $deps->isEmpty() ? '—' : $deps->implode(', ') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($effective !== [])
                    <flux:text class="mt-3 text-xs text-zinc-500">
                        {{ __('Approved by') }}:
                        {{ collect($effective)->map(fn ($a) => $a->approver?->name ?? 'unknown')->implode(', ') }}
                    </flux:text>
                @endif

                @if (! $canApprove)
                    <flux:text class="mt-3 text-xs text-zinc-500">
                        {{ __('Your role does not permit approval decisions on this project.') }}
                    </flux:text>
                @endif

                @if ($canApprove)
                    <flux:textarea
                        class="mt-3"
                        wire:model.defer="notes.plan:{{ $plan->id }}"
                        :placeholder="__('Notes (optional)')"
                    />
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($userApproved)
                            <flux:button wire:click="decide('plan', {{ $plan->id }}, 'revoke')">{{ __('Revoke approval') }}</flux:button>
                        @else
                            <flux:button variant="primary" wire:click="decide('plan', {{ $plan->id }}, 'approve')">{{ __('Approve') }}</flux:button>
                        @endif
                        <flux:button variant="danger" wire:click="decide('plan', {{ $plan->id }}, 'reject')">{{ __('Reject') }}</flux:button>
                        <flux:button wire:click="decide('plan', {{ $plan->id }}, 'changes_requested')">{{ __('Request changes') }}</flux:button>
                    </div>
                @endif
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No plans pending approval.') }}</flux:text>
        @endforelse
    </section>
</div>
