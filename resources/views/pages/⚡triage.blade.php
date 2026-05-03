<?php

use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Triage')] class extends Component {
    public array $notes = [];

    #[Computed]
    public function pendingStories()
    {
        $projectIds = Auth::user()->scopedProjectIds();

        return Story::query()
            ->where('status', StoryStatus::PendingApproval)
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with(['feature.project', 'acceptanceCriteria', 'creator', 'approvals.approver'])
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

    private function authorizedStory(int $id, array $projectIds, $user): Story
    {
        $story = Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with('feature.project')
            ->findOrFail($id);

        abort_unless($user->canApproveInProject($story->feature->project), 403);

        return $story;
    }
}; ?>

<div class="flex flex-col gap-8 p-6">
    <flux:heading size="xl">{{ __('Triage') }}</flux:heading>

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
                        <flux:button wire:click="decide({{ $story->id }}, 'changes_requested')">{{ __('Request changes') }}</flux:button>
                    </div>
                @endif
            </x-story.summary-card>
        @empty
            <flux:text class="text-zinc-500">{{ __('No stories pending approval.') }}</flux:text>
        @endforelse
    </section>
</div>
