<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ApprovalService;
use App\Services\ExecutionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Story')] class extends Component {
    public int $story_id;

    public ?string $approvalNote = null;

    public function mount(int $story): void
    {
        $this->story_id = $story;
        abort_unless($this->story, 404);
    }

    public function submit(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($story->status === StoryStatus::Draft, 422, 'Story is not a draft.');
        abort_unless($story->created_by_id === Auth::id() || Auth::user()->canApproveInProject($story->feature->project), 403);

        $story->submitForApproval();

        $fresh = $story->fresh();
        if ($fresh->status === StoryStatus::Approved && ! $fresh->tasks()->exists()) {
            app(ExecutionService::class)->dispatchTaskGeneration($fresh);
        }

        unset($this->story);
    }

    public function decide(string $decision): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        $user = Auth::user();
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        app(ApprovalService::class)->recordDecision(
            $story,
            $user,
            ApprovalDecision::from($decision),
            $this->approvalNote ?: null,
        );

        $this->approvalNote = null;
        unset($this->story);
    }

    public function generatePlan(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($story->status === StoryStatus::Approved, 422, 'Story must be Approved.');
        abort_if($story->tasks()->exists(), 422, 'Plan already exists.');
        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        app(ExecutionService::class)->dispatchTaskGeneration($story);

        unset($this->story);
    }

    public function resumeExecution(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($story->status === StoryStatus::Approved, 422, 'Story must be Approved.');
        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        $subtaskIds = Subtask::whereIn('task_id', $story->tasks()->pluck('id'))->pluck('id');

        AgentRun::where('runnable_type', Subtask::class)
            ->whereIn('runnable_id', $subtaskIds)
            ->whereIn('status', [AgentRunStatus::Queued, AgentRunStatus::Running])
            ->update([
                'status' => AgentRunStatus::Aborted->value,
                'error_message' => 'Aborted on resume.',
                'finished_at' => now(),
            ]);

        Subtask::whereIn('id', $subtaskIds)
            ->where('status', TaskStatus::Blocked)
            ->update(['status' => TaskStatus::Pending->value]);

        app(ExecutionService::class)->startStoryExecution($story->fresh());
        unset($this->story);
    }

    public function startExecution(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($story->status === StoryStatus::PendingApproval, 422, 'Story is not awaiting approval.');
        abort_unless($story->tasks()->exists(), 422, 'No plan to execute.');

        $policy = $story->effectivePolicy();
        abort_unless($policy->auto_approve || $policy->required_approvals === 0, 403, 'Policy requires explicit approvals.');
        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        app(ApprovalService::class)->recompute($story);
        unset($this->story);
    }

    #[Computed]
    public function pendingPlanRun(): ?AgentRun
    {
        return AgentRun::query()
            ->where('runnable_type', Story::class)
            ->where('runnable_id', $this->story_id)
            ->whereIn('status', [AgentRunStatus::Queued, AgentRunStatus::Running])
            ->latest('id')
            ->first();
    }

    #[Computed]
    public function story(): ?Story
    {
        return Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', Auth::user()->accessibleProjectIds()))
            ->with([
                'feature.project',
                'creator',
                'acceptanceCriteria',
                'tasks.acceptanceCriterion',
                'tasks.dependencies',
                'tasks.subtasks',
                'approvals.approver',
            ])
            ->find($this->story_id);
    }

    #[Computed]
    public function runs()
    {
        if (! $this->story) {
            return collect();
        }

        $taskIds = $this->story->tasks->pluck('id');
        $subtaskIds = $this->story->tasks->flatMap->subtasks->pluck('id');

        return AgentRun::query()
            ->where(function ($q) use ($taskIds, $subtaskIds) {
                $q->where(function ($qq) {
                    $qq->where('runnable_type', Story::class)->where('runnable_id', $this->story_id);
                });
                if ($taskIds->isNotEmpty()) {
                    $q->orWhere(function ($qq) use ($taskIds) {
                        $qq->where('runnable_type', Task::class)->whereIn('runnable_id', $taskIds);
                    });
                }
                if ($subtaskIds->isNotEmpty()) {
                    $q->orWhere(function ($qq) use ($subtaskIds) {
                        $qq->where('runnable_type', Subtask::class)->whereIn('runnable_id', $subtaskIds);
                    });
                }
            })
            ->with('repo', 'runnable')
            ->latest('id')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-6 p-6" @if ($this->pendingPlanRun) wire:poll.3s @endif>
    @if (! $this->story)
        <flux:text class="text-zinc-500">{{ __('Story not found.') }}</flux:text>
    @else
        @php
            $story = $this->story;
            $project = $story->feature->project;
        @endphp
        <div>
            <a href="{{ route('features.show', ['project' => $project->id, 'feature' => $story->feature_id]) }}" wire:navigate class="text-sm text-zinc-500 underline">
                &larr; {{ $project->name }} / {{ $story->feature->name }}
            </a>
            <flux:heading size="xl" class="mt-2">{{ $story->name }}</flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge>{{ $story->status->value }}</flux:badge>
                <flux:badge>rev {{ $story->revision }}</flux:badge>
                @if ($story->creator)
                    <flux:badge>{{ __('by') }} {{ $story->creator->name }}</flux:badge>
                @endif
            </div>
            <x-markdown :content="$story->description" class="mt-3" />
            @php
                $user = auth()->user();
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
                $userApproved = isset($effective[$user->id]);
                $canApprove = $user->canApproveInProject($project);
                $isAuthor = $story->created_by_id === $user->id;
                $blockedBySelfApproval = $isAuthor && ! $policy->allow_self_approval;
                $isAuthoringStatus = in_array($story->status, [StoryStatus::Draft, StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true);
            @endphp

            <div class="mt-4 flex flex-wrap items-center gap-2">
                @if ($story->status === StoryStatus::Draft && ($isAuthor || $canApprove))
                    @php
                        $autoApproves = $policy->auto_approve || $policy->required_approvals === 0;
                    @endphp
                    <flux:button wire:click="submit" wire:target="submit" wire:loading.attr="disabled" variant="primary">
                        <span wire:loading.remove wire:target="submit">{{ $autoApproves ? __('Generate plan') : __('Submit for approval') }}</span>
                        <span wire:loading wire:target="submit">{{ __('Working…') }}</span>
                    </flux:button>
                @endif

                @php
                    $autoPromotes = $policy->auto_approve || $policy->required_approvals === 0;
                @endphp

                @if ($story->status === StoryStatus::PendingApproval && $story->tasks->isNotEmpty() && $autoPromotes && $canApprove)
                    <flux:button wire:click="startExecution" wire:target="startExecution" wire:loading.attr="disabled" variant="primary">
                        <span wire:loading.remove wire:target="startExecution">{{ __('Start execution') }}</span>
                        <span wire:loading wire:target="startExecution">{{ __('Working…') }}</span>
                    </flux:button>
                @elseif (in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true) && $canApprove && ! $blockedBySelfApproval)
                    @if ($userApproved)
                        <flux:button wire:click="decide('revoke')" wire:target="decide" wire:loading.attr="disabled">{{ __('Revoke approval') }}</flux:button>
                    @else
                        <flux:button wire:click="decide('approve')" wire:target="decide" wire:loading.attr="disabled" variant="primary">{{ __('Approve') }}</flux:button>
                    @endif
                    <flux:button wire:click="decide('changes_requested')" wire:target="decide" wire:loading.attr="disabled">{{ __('Request changes') }}</flux:button>
                    <flux:button wire:click="decide('reject')" wire:target="decide" wire:loading.attr="disabled" variant="danger">{{ __('Reject') }}</flux:button>
                @endif

                @if ($isAuthoringStatus && $canApprove && ! $blockedBySelfApproval && ! $autoPromotes)
                    <flux:badge>{{ count($effective) }}/{{ $policy->required_approvals }} {{ __('approvals') }}</flux:badge>
                @endif

                @if ($this->pendingPlanRun)
                    <flux:badge color="amber">{{ __('Generating plan…') }}</flux:badge>
                @endif

                @php
                    $hasIncompleteWork = $story->status === StoryStatus::Approved
                        && $story->tasks->isNotEmpty()
                        && $story->tasks->flatMap->subtasks->contains(fn ($s) => $s->status !== TaskStatus::Done);
                @endphp
                @if ($hasIncompleteWork && $canApprove)
                    <flux:button wire:click="resumeExecution" wire:target="resumeExecution" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="resumeExecution">{{ __('Resume execution') }}</span>
                        <span wire:loading wire:target="resumeExecution">{{ __('Working…') }}</span>
                    </flux:button>
                @endif
            </div>

            @if (in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true) && $canApprove && ! $blockedBySelfApproval && ! $autoPromotes)
                <flux:textarea
                    class="mt-2"
                    wire:model.defer="approvalNote"
                    :placeholder="__('Notes (optional, attached to your decision)')"
                />
            @endif

            @if ($blockedBySelfApproval && ! $autoPromotes && in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true))
                <flux:text class="mt-3 text-xs text-amber-600">
                    {{ __('You authored this story; the policy disallows self-approval.') }}
                </flux:text>
            @endif

            @if ($story->notes)
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Notes') }}</summary>
                    <x-markdown :content="$story->notes" class="mt-2" />
                </details>
            @endif
        </div>

        @if ($story->acceptanceCriteria->isNotEmpty())
            <section class="flex flex-col gap-2">
                <flux:heading size="lg">{{ __('Acceptance criteria') }}</flux:heading>
                <ul class="text-sm">
                    @foreach ($story->acceptanceCriteria as $ac)
                        <li class="py-0.5">
                            <flux:badge class="mr-2 align-middle">{{ __('AC') }} #{{ $ac->position }}</flux:badge>
                            {{ $ac->criterion }}
                            @if ($ac->met)<flux:badge class="ml-2">{{ __('met') }}</flux:badge>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Plan') }}</flux:heading>
                @if ($story->status === StoryStatus::Approved && $story->tasks->isEmpty() && ! $this->pendingPlanRun && auth()->user()->canApproveInProject($story->feature->project))
                    <flux:button wire:click="generatePlan" wire:target="generatePlan" wire:loading.attr="disabled" variant="primary">
                        <span wire:loading.remove wire:target="generatePlan">{{ __('Generate plan') }}</span>
                        <span wire:loading wire:target="generatePlan">{{ __('Working…') }}</span>
                    </flux:button>
                @endif
            </div>
            @forelse ($story->tasks->sortBy('position') as $task)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">#{{ $task->position }}</flux:badge>
                        <flux:badge>{{ $task->status->value }}</flux:badge>
                        @if ($task->acceptanceCriterion)
                            <flux:badge>{{ __('AC') }} #{{ $task->acceptanceCriterion->position }}</flux:badge>
                        @endif
                        @php
                            $deps = $task->dependencies->map(fn ($d) => '#'.$d->position);
                        @endphp
                        @if ($deps->isNotEmpty())
                            <flux:badge>{{ __('depends on') }} {{ $deps->implode(', ') }}</flux:badge>
                        @endif
                    </div>
                    <flux:heading class="mt-2">{{ $task->name }}</flux:heading>
                    @if ($task->acceptanceCriterion)
                        <flux:text class="mt-1 text-sm text-zinc-500"><em>{{ $task->acceptanceCriterion->criterion }}</em></flux:text>
                    @endif
                    @if ($task->description)
                        <x-markdown :content="$task->description" class="mt-2 text-sm" />
                    @endif
                    @if ($task->subtasks->isNotEmpty())
                        <ol class="mt-3 list-decimal pl-5 text-sm">
                            @foreach ($task->subtasks->sortBy('position') as $sub)
                                <li class="py-1">
                                    <span class="font-medium">{{ $sub->name }}</span>
                                    <flux:badge class="ml-2">{{ $sub->status->value }}</flux:badge>
                                    @if ($sub->description)
                                        <x-markdown :content="$sub->description" class="mt-1 text-xs text-zinc-500" />
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </flux:card>
            @empty
                @if ($this->pendingPlanRun)
                    <flux:text class="text-zinc-500">{{ __('Generating plan…') }}</flux:text>
                @elseif ($story->status === StoryStatus::Approved)
                    <flux:text class="text-zinc-500">{{ __('No plan yet — generate one to break this story into tasks and subtasks.') }}</flux:text>
                @else
                    <flux:text class="text-zinc-500">{{ __('Plan is generated once the story is approved.') }}</flux:text>
                @endif
            @endforelse
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Runs') }}</flux:heading>
            @forelse ($this->runs as $run)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge variant="solid">#{{ $run->id }}</flux:badge>
                        <flux:badge>{{ $run->status->value }}</flux:badge>
                        @if ($run->repo)
                            <flux:badge>{{ $run->repo->name }}</flux:badge>
                        @endif
                        @if ($run->working_branch)
                            <flux:badge>{{ $run->working_branch }}</flux:badge>
                        @endif
                        @if ($run->finished_at)
                            <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                        @endif
                    </div>
                    @if ($run->runnable instanceof Subtask)
                        <flux:text class="mt-2 text-sm">{{ $run->runnable->name }}</flux:text>
                    @elseif ($run->runnable)
                        <flux:text class="mt-2 text-sm">{{ $run->runnable->name }}</flux:text>
                    @endif
                    @if ($url = $run->output['pull_request_url'] ?? null)
                        <flux:text class="mt-1">
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ $url }}</a>
                        </flux:text>
                    @endif
                    @if ($run->error_message)
                        <flux:text class="mt-1 text-red-600">{{ $run->error_message }}</flux:text>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No runs yet.') }}</flux:text>
            @endforelse
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Story approvals') }}</flux:heading>
            @forelse ($story->approvals->sortByDesc('created_at') as $approval)
                <flux:card>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge>{{ $approval->decision->value }}</flux:badge>
                        <flux:badge>rev {{ $approval->story_revision }}</flux:badge>
                        <flux:text class="text-sm">{{ $approval->approver?->name ?? 'unknown' }}</flux:text>
                        <flux:text class="ml-auto text-xs text-zinc-500">{{ $approval->created_at?->diffForHumans() }}</flux:text>
                    </div>
                    @if ($approval->notes)
                        <flux:text class="mt-1 text-sm">{{ $approval->notes }}</flux:text>
                    @endif
                </flux:card>
            @empty
                <flux:text class="text-zinc-500">{{ __('No story approvals yet.') }}</flux:text>
            @endforelse
        </section>
    @endif
</div>
