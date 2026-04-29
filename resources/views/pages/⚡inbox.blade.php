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
            ->with(['feature.project', 'acceptanceCriteria'])
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
            ->with(['story.feature.project', 'tasks'])
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

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Stories pending approval') }}</flux:heading>
        @forelse ($this->pendingStories as $story)
            <flux:card>
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:badge variant="solid">{{ $story->feature->project->name }}</flux:badge>
                            <flux:badge>rev {{ $story->revision }}</flux:badge>
                        </div>
                        <flux:heading class="mt-2">{{ $story->name }}</flux:heading>
                        <flux:text class="mt-1">{{ $story->description }}</flux:text>
                        @if ($story->acceptanceCriteria->isNotEmpty())
                            <ul class="mt-3 list-disc pl-5 text-sm">
                                @foreach ($story->acceptanceCriteria as $ac)
                                    <li>{{ $ac->statement }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                <flux:textarea
                    class="mt-3"
                    wire:model.defer="notes.story:{{ $story->id }}"
                    :placeholder="__('Notes (optional)')"
                />
                <div class="mt-3 flex gap-2">
                    <flux:button variant="primary" wire:click="decide('story', {{ $story->id }}, 'approve')">{{ __('Approve') }}</flux:button>
                    <flux:button variant="danger" wire:click="decide('story', {{ $story->id }}, 'reject')">{{ __('Reject') }}</flux:button>
                    <flux:button wire:click="decide('story', {{ $story->id }}, 'changes_requested')">{{ __('Request changes') }}</flux:button>
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No stories pending approval.') }}</flux:text>
        @endforelse
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Plans pending approval') }}</flux:heading>
        @forelse ($this->pendingPlans as $plan)
            <flux:card>
                <div class="flex items-center gap-2">
                    <flux:badge variant="solid">{{ $plan->story->feature->project->name }}</flux:badge>
                    <flux:badge>v{{ $plan->version }}</flux:badge>
                </div>
                <flux:heading class="mt-2">{{ $plan->story->name }}</flux:heading>
                @if ($plan->summary)
                    <flux:text class="mt-1">{{ $plan->summary }}</flux:text>
                @endif
                <ol class="mt-3 list-decimal pl-5 text-sm">
                    @foreach ($plan->tasks as $task)
                        <li>{{ $task->name }}</li>
                    @endforeach
                </ol>
                <flux:textarea
                    class="mt-3"
                    wire:model.defer="notes.plan:{{ $plan->id }}"
                    :placeholder="__('Notes (optional)')"
                />
                <div class="mt-3 flex gap-2">
                    <flux:button variant="primary" wire:click="decide('plan', {{ $plan->id }}, 'approve')">{{ __('Approve') }}</flux:button>
                    <flux:button variant="danger" wire:click="decide('plan', {{ $plan->id }}, 'reject')">{{ __('Reject') }}</flux:button>
                    <flux:button wire:click="decide('plan', {{ $plan->id }}, 'changes_requested')">{{ __('Request changes') }}</flux:button>
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No plans pending approval.') }}</flux:text>
        @endforelse
    </section>
</div>
