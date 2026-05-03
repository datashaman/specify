<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ApprovalService;
use App\Services\ExecutionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Story')] class extends Component {
    public int $story_id;

    public ?string $approvalNote = null;

    public bool $editing = false;

    #[Validate('required|string|max:255')]
    public string $editName = '';

    #[Validate('required|string')]
    public string $editDescription = '';

    /** @var array<int, array{id: ?int, criterion: string}> */
    public array $editCriteria = [];

    public function mount(int $story, ?int $project = null): void
    {
        $this->story_id = $story;
        $loaded = $this->story;
        abort_unless($loaded, 404);

        if ($project !== null) {
            abort_unless((int) $loaded->feature->project_id === (int) $project, 404);
            $user = Auth::user();
            if ((int) $user->current_project_id !== (int) $project) {
                $user->forceFill(['current_project_id' => (int) $project])->save();
            }
        }
    }

    public function startEdit(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($this->canEdit($story), 403);

        $this->editName = (string) $story->name;
        $this->editDescription = (string) $story->description;
        $this->editCriteria = $story->acceptanceCriteria
            ->sortBy('position')
            ->values()
            ->map(fn (AcceptanceCriterion $ac) => ['id' => $ac->id, 'criterion' => (string) $ac->criterion])
            ->all();
        if ($this->editCriteria === []) {
            $this->editCriteria = [['id' => null, 'criterion' => '']];
        }
        $this->editing = true;
    }

    public function addCriterion(): void
    {
        $this->editCriteria[] = ['id' => null, 'criterion' => ''];
    }

    public function removeCriterion(int $index): void
    {
        if (! isset($this->editCriteria[$index])) {
            return;
        }
        unset($this->editCriteria[$index]);
        $this->editCriteria = array_values($this->editCriteria);
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->editName = '';
        $this->editDescription = '';
        $this->editCriteria = [];
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($this->canEdit($story), 403);
        abort_if(in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true), 422, 'Story is read-only.');

        $this->validate([
            'editName' => 'required|string|max:255',
            'editDescription' => 'required|string',
            'editCriteria' => 'array|min:1',
            'editCriteria.*.criterion' => 'required|string|max:1000',
        ]);

        $criteriaChanged = $this->syncCriteria($story);

        $story->update([
            'name' => trim($this->editName),
            'description' => $this->editDescription,
        ]);

        if ($criteriaChanged && in_array($story->status, [StoryStatus::Approved, StoryStatus::ChangesRequested], true)) {
            $story->silentlyForceFill([
                'status' => StoryStatus::PendingApproval->value,
                'revision' => ($story->revision ?? 1) + 1,
            ]);
            app(ApprovalService::class)->recompute($story->fresh());
        }

        $this->editing = false;
        unset($this->story);
    }

    private function syncCriteria(Story $story): bool
    {
        $existing = $story->acceptanceCriteria()->get()->keyBy('id');
        $kept = [];
        $changed = false;

        return DB::transaction(function () use ($story, $existing, &$kept, &$changed) {
            foreach ($this->editCriteria as $i => $row) {
                $position = $i + 1;
                $text = trim((string) ($row['criterion'] ?? ''));
                $id = $row['id'] ?? null;

                if ($id !== null && $existing->has($id)) {
                    $ac = $existing[$id];
                    if ($ac->criterion !== $text || $ac->position !== $position) {
                        $ac->update(['criterion' => $text, 'position' => $position]);
                        $changed = true;
                    }
                    $kept[] = $id;
                } else {
                    AcceptanceCriterion::create([
                        'story_id' => $story->id,
                        'position' => $position,
                        'criterion' => $text,
                    ]);
                    $changed = true;
                }
            }

            $toDelete = $existing->keys()->diff($kept);
            if ($toDelete->isNotEmpty()) {
                AcceptanceCriterion::whereIn('id', $toDelete)->delete();
                $changed = true;
            }

            return $changed;
        });
    }

    private function canEdit(Story $story): bool
    {
        $user = Auth::user();

        return $story->created_by_id === $user->id
            || $user->canApproveInProject($story->feature->project);
    }

    public function canEditStory(): bool
    {
        $story = $this->story;
        if (! $story) {
            return false;
        }
        if (in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true)) {
            return false;
        }

        return $this->canEdit($story);
    }

    public function canDeleteStory(): bool
    {
        $story = $this->story;
        if (! $story) {
            return false;
        }
        if (! in_array($story->status, [
            StoryStatus::Draft,
            StoryStatus::ProposedByAI,
            StoryStatus::Cancelled,
            StoryStatus::Rejected,
        ], true)) {
            return false;
        }

        return $this->canEdit($story);
    }

    public function deleteStory(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($this->canDeleteStory(), 403);

        $featureId = $story->feature_id;
        $projectId = $story->feature->project_id;

        $story->delete();

        $this->redirectRoute('features.show', ['project' => $projectId, 'feature' => $featureId], navigate: true);
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

    /**
     * Visual rail state derived from the Story status. Does not double as the
     * pill text — the pill carries the tally, the rail is the invariant carrier.
     */
    #[Computed]
    public function railState(): string
    {
        $story = $this->story;
        if (! $story) {
            return 'draft';
        }
        if ($this->hasActiveSubtaskRun) {
            return 'running';
        }

        return match ($story->status) {
            StoryStatus::Draft, StoryStatus::ProposedByAI => 'draft',
            StoryStatus::PendingApproval => 'pending',
            StoryStatus::Approved => 'approved',
            StoryStatus::ChangesRequested => 'changes_requested',
            StoryStatus::Rejected, StoryStatus::Cancelled => 'rejected',
            StoryStatus::Done => 'run_complete',
        };
    }

    /**
     * Pill state + tally + label for the breadcrumb. Returns the data shape
     * the x-state-pill component consumes.
     *
     * @return array{state: string, tally: ?string, label: ?string}
     */
    #[Computed]
    public function pill(): array
    {
        $story = $this->story;
        if (! $story) {
            return ['state' => 'draft', 'tally' => null, 'label' => null];
        }
        $policy = $this->effectivePolicy;
        $required = $policy?->required_approvals ?? 0;
        $count = count($this->effectiveApprovals);

        return match ($story->status) {
            StoryStatus::Draft => ['state' => 'draft', 'tally' => null, 'label' => __('Draft')],
            StoryStatus::ProposedByAI => ['state' => 'draft', 'tally' => null, 'label' => __('Proposed by AI')],
            StoryStatus::PendingApproval => [
                'state' => 'pending',
                'tally' => $required > 0 ? sprintf('%d/%d', $count, $required) : null,
                'label' => __('Pending'),
            ],
            StoryStatus::Approved => [
                'state' => 'approved',
                'tally' => $required > 0 ? sprintf('%d/%d', $required, $required) : null,
                'label' => __('Approved'),
            ],
            StoryStatus::ChangesRequested => ['state' => 'changes_requested', 'tally' => null, 'label' => __('Changes requested')],
            StoryStatus::Rejected => ['state' => 'rejected', 'tally' => null, 'label' => __('Rejected')],
            StoryStatus::Cancelled => ['state' => 'rejected', 'tally' => null, 'label' => __('Cancelled')],
            StoryStatus::Done => ['state' => 'run_complete', 'tally' => null, 'label' => __('Done')],
        };
    }

    #[Computed]
    public function hasActiveSubtaskRun(): bool
    {
        $story = $this->story;
        if (! $story) {
            return false;
        }

        return $story->tasks->flatMap->subtasks->flatMap->agentRuns
            ->contains(fn (AgentRun $run) => in_array(
                $run->status,
                [AgentRunStatus::Queued, AgentRunStatus::Running],
                true,
            ));
    }

    /**
     * AC counts/edits/removes between the live edit form and the persisted ACs.
     * Returns counts only when status is in [Approved, ChangesRequested] AND
     * something has actually changed; otherwise null. Drives the reset-approval
     * banner and the Save button label.
     *
     * @return array{added: int, edited: int, removed: int}|null
     */
    #[Computed]
    public function acDelta(): ?array
    {
        $story = $this->story;
        if (! $story || ! $this->editing) {
            return null;
        }
        if (! in_array($story->status, [StoryStatus::Approved, StoryStatus::ChangesRequested], true)) {
            return null;
        }

        $existing = $story->acceptanceCriteria->keyBy('id');
        $added = 0;
        $edited = 0;
        $kept = [];
        foreach ($this->editCriteria as $row) {
            $id = $row['id'] ?? null;
            $text = trim((string) ($row['criterion'] ?? ''));
            if ($id === null) {
                if ($text !== '') {
                    $added++;
                }
                continue;
            }
            if (! $existing->has($id)) {
                continue;
            }
            $kept[] = $id;
            if (trim((string) $existing[$id]->criterion) !== $text) {
                $edited++;
            }
        }
        $removed = $existing->keys()->diff($kept)->count();

        if ($added === 0 && $edited === 0 && $removed === 0) {
            return null;
        }

        return ['added' => $added, 'edited' => $edited, 'removed' => $removed];
    }

    /**
     * Eligible approvers for the current Story; lazy so the page doesn't pay
     * the cost when the policy threshold is 1. When the policy disallows
     * self-approval, the author is filtered out — they cannot count toward
     * the threshold so listing them as "eligible" is misleading.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    #[Computed]
    public function eligibleApprovers()
    {
        $story = $this->story;
        $policy = $this->effectivePolicy;
        if (! $story || ! $policy || ($policy->required_approvals ?? 0) <= 1) {
            return collect();
        }

        $excludeAuthor = ! ($policy->allow_self_approval ?? false);

        return \App\Models\User::query()
            ->whereHas('teams.workspace', fn ($q) => $q->where('workspaces.id', $story->feature->project->team->workspace_id))
            ->orderBy('name')
            ->get()
            ->filter(fn (\App\Models\User $u) => $u->canApproveInProject($story->feature->project))
            ->reject(fn (\App\Models\User $u) => $excludeAuthor && $u->id === $story->created_by_id)
            ->values();
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
                'tasks.subtasks.agentRuns.repo',
                'approvals.approver',
            ])
            ->find($this->story_id);
    }

    #[Computed]
    public function effectivePolicy()
    {
        return $this->story?->effectivePolicy();
    }

    /**
     * Live approvals for the current revision: replay approve/revoke per approver.
     *
     * @return array<int, \App\Models\StoryApproval>
     */
    #[Computed]
    public function effectiveApprovals(): array
    {
        $story = $this->story;
        if (! $story) {
            return [];
        }
        $effective = [];
        foreach ($story->approvals->where('story_revision', $story->revision ?? 1)->sortBy('created_at') as $a) {
            $key = (int) $a->approver_id;
            if ($a->decision === ApprovalDecision::Approve) {
                $effective[$key] = $a;
            } elseif ($a->decision === ApprovalDecision::Revoke) {
                unset($effective[$key]);
            }
        }

        return $effective;
    }

    #[Computed]
    public function userApproved(): bool
    {
        return isset($this->effectiveApprovals[Auth::id()]);
    }

    #[Computed]
    public function canApproveStory(): bool
    {
        $story = $this->story;

        return $story !== null && Auth::user()->canApproveInProject($story->feature->project);
    }

    #[Computed]
    public function isAuthor(): bool
    {
        return $this->story?->created_by_id === Auth::id();
    }

    #[Computed]
    public function blockedBySelfApproval(): bool
    {
        $policy = $this->effectivePolicy;

        return $this->isAuthor && $policy && ! $policy->allow_self_approval;
    }

    #[Computed]
    public function autoPromotes(): bool
    {
        $policy = $this->effectivePolicy;

        return $policy !== null && ($policy->auto_approve || $policy->required_approvals === 0);
    }

    #[Computed]
    public function isAuthoringStatus(): bool
    {
        $story = $this->story;

        return $story !== null && in_array(
            $story->status,
            [StoryStatus::Draft, StoryStatus::PendingApproval, StoryStatus::ChangesRequested],
            true,
        );
    }

    #[Computed]
    public function decisionPanelVisible(): bool
    {
        $story = $this->story;

        return ! $this->editing
            && $story !== null
            && ! in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true);
    }

    /**
     * Story-runnable AgentRuns: plan-generation runs, latest first.
     */
    #[Computed]
    public function planGenerationRuns()
    {
        if (! $this->story) {
            return collect();
        }

        return AgentRun::query()
            ->where('runnable_type', Story::class)
            ->where('runnable_id', $this->story_id)
            ->latest('id')
            ->get();
    }
}; ?>

<div
    class="flex p-6"
    @if ($this->pendingPlanRun) wire:poll.3s @endif
    x-data="{
        planRunMode: localStorage.getItem('specify.planRunMode') === '1',
        init() {
            this.$watch('planRunMode', v => localStorage.setItem('specify.planRunMode', v ? '1' : '0'));
        },
    }"
>
    @if (! $this->story)
        <flux:text class="text-zinc-500">{{ __('Story not found.') }}</flux:text>
    @else
        @php
            $story = $this->story;
            $project = $story->feature->project;
            $pill = $this->pill;
        @endphp

        <x-rail :state="$this->railState" class="mr-4" />

        <div class="flex min-w-0 flex-1 flex-col gap-6 lg:flex-row">
            <div class="flex min-w-0 max-w-4xl flex-1 flex-col gap-6">

        {{-- ── Header ──────────────────────────────────────────── --}}
        <div>
            <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-sm text-zinc-500" data-section="breadcrumb">
                <a href="{{ route('projects.show', $project->id) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $project->name }}</a>
                <span aria-hidden="true">›</span>
                <a href="{{ route('features.show', ['project' => $project->id, 'feature' => $story->feature_id]) }}" wire:navigate class="hover:text-zinc-700 hover:underline dark:hover:text-zinc-300">{{ $story->feature->name }}</a>
                <span aria-hidden="true">›</span>
                <span class="text-zinc-700 dark:text-zinc-300" aria-current="page">{{ $story->name }}</span>
            </nav>

            @if ($editing)
                <div class="mt-3 flex flex-col gap-3">
                    @php $delta = $this->acDelta; @endphp
                    @if ($delta)
                        <div data-banner="reset-approval" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
                            <div class="font-medium">{{ __('Saving will reset this Story to Pending Approval.') }}</div>
                            <div class="mt-1 text-xs">
                                {{ __('Plan delta:') }}
                                <span class="tabular-nums">+{{ $delta['added'] }}</span> {{ __('AC,') }}
                                <span class="tabular-nums">~{{ $delta['edited'] }}</span> {{ __('edited,') }}
                                <span class="tabular-nums">-{{ $delta['removed'] }}</span> {{ __('removed') }}
                            </div>
                        </div>
                    @endif

                    <flux:input wire:model="editName" :label="__('Name')" />
                    <flux:textarea wire:model="editDescription" :label="__('Description (markdown supported)')" rows="8" />

                    <div class="flex flex-col gap-2">
                        <flux:label>{{ __('Acceptance criteria') }}</flux:label>
                        @foreach ($editCriteria as $i => $row)
                            <div wire:key="ac-{{ $i }}" class="flex items-start gap-2">
                                <flux:badge class="mt-2" size="sm">AC{{ $i + 1 }}</flux:badge>
                                <flux:textarea wire:model="editCriteria.{{ $i }}.criterion" rows="2" class="flex-1" />
                                <flux:button wire:click="removeCriterion({{ $i }})" variant="ghost" size="sm" class="mt-1">{{ __('Remove') }}</flux:button>
                            </div>
                        @endforeach
                        <div>
                            <flux:button wire:click="addCriterion" variant="ghost" size="sm">{{ __('+ Add criterion') }}</flux:button>
                        </div>
                        @error('editCriteria')
                            <flux:text class="text-xs text-red-500">{{ $message }}</flux:text>
                        @enderror
                    </div>

                    <div class="flex items-center gap-2">
                        @php $saveLabel = $this->acDelta ? __('Save & request re-approval') : __('Save'); @endphp
                        <flux:button wire:click="saveEdit" wire:target="saveEdit" wire:loading.attr="disabled" variant="primary">
                            <span wire:loading.remove wire:target="saveEdit">{{ $saveLabel }}</span>
                            <span wire:loading wire:target="saveEdit">{{ __('Saving…') }}</span>
                        </flux:button>
                        <flux:button wire:click="cancelEdit" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </div>
                </div>
            @else
                <div class="mt-2 flex items-start justify-between gap-3">
                    <flux:heading size="xl">{{ $story->name }}</flux:heading>
                    <div class="flex items-center gap-2">
                        @if ($this->canEditStory())
                            <flux:button wire:click="startEdit" size="sm" icon="pencil-square">{{ __('Edit') }}</flux:button>
                        @endif
                        @if ($this->canDeleteStory())
                            <flux:modal.trigger name="delete-story-modal">
                                <flux:button size="sm" variant="danger" icon="trash">{{ __('Delete') }}</flux:button>
                            </flux:modal.trigger>
                        @endif
                    </div>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-state-pill :state="$pill['state']" :tally="$pill['tally']" :label="$pill['label']" />
                    <flux:badge>{{ __('rev') }} {{ $story->revision }}</flux:badge>
                    @if ($story->creator)
                        <flux:avatar
                            size="xs"
                            :name="$story->creator->name"
                            :initials="$story->creator->initials()"
                            :tooltip="$story->creator->name"
                        />
                    @endif
                </div>

                @if ($this->canDeleteStory())
                    <flux:modal name="delete-story-modal" class="md:w-96">
                        <div class="flex flex-col gap-4">
                            <flux:heading size="lg">{{ __('Delete story?') }}</flux:heading>
                            <flux:text>{{ __('This permanently removes the story and its acceptance criteria. Cannot be undone.') }}</flux:text>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button wire:click="deleteStory" variant="danger" icon="trash">{{ __('Delete') }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif

                @php $storyPrs = $story->pullRequests(); @endphp
                @if ($storyPrs->isNotEmpty())
                    <div class="mt-3 flex flex-col gap-1.5" data-section="story-prs">
                        <div class="flex items-baseline gap-2">
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">
                                {{ trans_choice('Pull request|Pull requests', $storyPrs->count()) }}
                            </flux:text>
                            @if ($storyPrs->count() > 1)
                                <flux:text class="text-xs text-zinc-400">
                                    {{ __('race candidates — reviewer picks the winner by merging it') }}
                                </flux:text>
                            @endif
                        </div>
                        <div class="flex flex-col gap-1">
                            @foreach ($storyPrs as $pr)
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <a
                                        href="{{ $pr['url'] }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex"
                                    >
                                        <flux:badge size="sm" :color="$pr['merged'] === true ? 'emerald' : 'zinc'" icon="arrow-top-right-on-square">
                                            @if ($pr['merged'] === true)
                                                {{ __('merged') }}
                                            @elseif ($pr['merged'] === false)
                                                {{ __('open') }}
                                            @else
                                                {{ __('PR') }}
                                            @endif
                                            @if ($pr['number'])
                                                #{{ $pr['number'] }}
                                            @endif
                                        </flux:badge>
                                    </a>
                                    @if ($pr['driver'])
                                        <flux:badge size="sm" icon="cpu-chip">{{ $pr['driver'] }}</flux:badge>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>

        @unless ($editing)
            {{-- ── Story body: description + notes (ACs lead the plan section below) ── --}}
            <section class="flex flex-col gap-3">
                <x-markdown :content="$story->description" />

                @if ($story->notes)
                    <details>
                        <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Notes') }}</summary>
                        <x-markdown :content="$story->notes" class="mt-2" />
                    </details>
                @endif
            </section>
        @endunless

        @php
            $policy = $this->effectivePolicy;
            $userApproved = $this->userApproved;
            $canApprove = $this->canApproveStory;
            $isAuthor = $this->isAuthor;
            $blockedBySelfApproval = $this->blockedBySelfApproval;
            $autoPromotes = $this->autoPromotes;
            $hasIncompleteWork = $story->status === StoryStatus::Approved
                && $story->tasks->isNotEmpty()
                && $story->tasks->flatMap->subtasks->contains(fn ($s) => $s->status !== TaskStatus::Done);
            $needsApprovalNote = in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true)
                && $canApprove
                && ! $blockedBySelfApproval
                && ! $autoPromotes;
            $hasDraftSubmit = $story->status === StoryStatus::Draft && ($isAuthor || $canApprove);
            $hasAutoStart = $story->status === StoryStatus::PendingApproval && $story->tasks->isNotEmpty() && $autoPromotes && $canApprove;
            $hasApprovalActions = in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true) && $canApprove && ! $blockedBySelfApproval;
            $hasResume = $hasIncompleteWork && $canApprove;
            $hasAnyDecisionAction = $hasDraftSubmit || $hasAutoStart || $hasApprovalActions || $hasResume;
            $isTerminal = in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true);
        @endphp

        @unless ($editing)
            {{-- ── Plan: ACs → Tasks → Subtasks → runs (AC-led) ────────────── --}}
            @php
                $tasksByAc = $story->tasks->groupBy('acceptance_criterion_id');
                $unmappedTasks = $tasksByAc->get(null, collect())->sortBy('position')->values();
                $acs = $story->acceptanceCriteria->sortBy('position')->values();
                $subtaskCount = $story->tasks->reduce(fn ($acc, $task) => $acc + $task->subtasks->count(), 0);
                $shouldRunMode = $this->hasActiveSubtaskRun;
                $latestRun = $story->tasks->flatMap->subtasks->flatMap->agentRuns->sortByDesc('id')->first();
                $branch = $latestRun?->working_branch;
                $repo = $latestRun?->repo;
            @endphp

            <section class="flex flex-col gap-3" data-section="plan">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <flux:heading size="lg">{{ __('Plan') }}</flux:heading>
                        <flux:text class="text-xs text-zinc-500">
                            {{ $acs->count() }} {{ __('ACs') }} · {{ $subtaskCount }} {{ __('subtasks') }}
                        </flux:text>
                        @if ($repo)
                            <flux:badge size="sm" icon="folder">{{ $repo->name }}</flux:badge>
                        @endif
                        @if ($branch)
                            <flux:text class="font-mono text-xs text-zinc-400 truncate max-w-[24rem]" :title="$branch">{{ $branch }}</flux:text>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($acs->isNotEmpty() || $unmappedTasks->isNotEmpty())
                            <div class="inline-flex rounded-md border border-zinc-200 p-0.5 text-xs dark:border-zinc-700" role="tablist" data-toggle="plan-mode" aria-label="{{ __('Plan view density') }}">
                                <button
                                    type="button"
                                    role="tab"
                                    @click="planRunMode = false"
                                    :aria-selected="!planRunMode ? 'true' : 'false'"
                                    :class="!planRunMode ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-300'"
                                    class="rounded px-2 py-0.5"
                                >{{ __('Compact') }}</button>
                                <button
                                    type="button"
                                    role="tab"
                                    @click="planRunMode = true"
                                    :aria-selected="planRunMode ? 'true' : 'false'"
                                    :class="planRunMode ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-300'"
                                    class="rounded px-2 py-0.5"
                                >{{ __('Expanded') }}</button>
                            </div>
                        @endif

                        @if ($story->status === StoryStatus::Approved && $story->tasks->isEmpty() && ! $this->pendingPlanRun && $this->canApproveStory)
                            <flux:button wire:click="generatePlan" wire:target="generatePlan" wire:loading.attr="disabled" variant="primary">
                                <span wire:loading.remove wire:target="generatePlan">{{ __('Generate plan') }}</span>
                                <span wire:loading wire:target="generatePlan">{{ __('Working…') }}</span>
                            </flux:button>
                        @endif
                    </div>
                </div>

                @forelse ($acs as $ac)
                    @php
                        $acTasks = $tasksByAc->get($ac->id, collect())->sortBy('position');
                    @endphp
                    <flux:card data-ac="{{ $loop->iteration }}" data-ac-id="{{ $ac->id }}">
                        <details
                            class="group"
                            x-data="{ open: {{ $shouldRunMode ? 'true' : 'false' }} || planRunMode }"
                            x-effect="planRunMode ? (open = true) : ({{ $shouldRunMode ? 'true' : 'false' }} || (open = false))"
                            :open="open"
                            @toggle="open = $event.target.open"
                        >
                            <summary class="flex cursor-pointer list-none flex-wrap items-baseline gap-2 text-sm [&::-webkit-details-marker]:hidden">
                                <span class="text-zinc-400 transition-transform group-open:rotate-90" aria-hidden="true">▸</span>
                                <flux:badge size="sm">AC{{ $loop->iteration }}</flux:badge>
                                <span class="font-medium">{{ $ac->criterion }}</span>
                            </summary>

                            @if ($acTasks->isEmpty())
                                <flux:text class="mt-2 text-xs text-zinc-500">{{ __('No task generated for this AC yet.') }}</flux:text>
                            @else
                                @foreach ($acTasks as $task)
                                    @include('partials.story-task', ['task' => $task])
                                @endforeach
                            @endif
                        </details>
                    </flux:card>
                @empty
                    @if ($this->pendingPlanRun)
                        <flux:text class="text-zinc-500">{{ __('Generating plan…') }}</flux:text>
                    @elseif ($story->acceptanceCriteria->isEmpty())
                        <flux:text class="text-zinc-500">{{ __('No acceptance criteria yet.') }}</flux:text>
                    @endif
                @endforelse

                @if ($unmappedTasks->isNotEmpty())
                    <flux:card data-ac="unmapped">
                        <details :open="planRunMode">
                            <summary class="cursor-pointer text-sm font-medium">
                                {{ __('Unmapped tasks') }} ({{ $unmappedTasks->count() }})
                            </summary>
                            @foreach ($unmappedTasks as $task)
                                @include('partials.story-task', ['task' => $task])
                            @endforeach
                        </details>
                    </flux:card>
                @endif

                @if ($acs->isEmpty() && $story->tasks->isEmpty() && ! $this->pendingPlanRun && $story->status !== StoryStatus::Approved)
                    <flux:text class="text-zinc-500">{{ __('Plan is generated once the story is approved.') }}</flux:text>
                @endif

                @php $planGenRuns = $this->planGenerationRuns; @endphp
                @if ($planGenRuns->isNotEmpty())
                    <details class="mt-1">
                        <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Plan generation runs') }} ({{ $planGenRuns->count() }})</summary>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($planGenRuns as $run)
                                <div class="rounded border border-zinc-200 px-2 py-1 dark:border-zinc-700">
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <flux:badge size="sm">#{{ $run->id }}</flux:badge>
                                        <flux:badge size="sm">{{ $run->status->value }}</flux:badge>
                                        @if ($run->finished_at)
                                            <flux:text class="ml-auto text-xs text-zinc-500">{{ $run->finished_at->diffForHumans() }}</flux:text>
                                        @endif
                                    </div>
                                    @if ($run->error_message)
                                        <flux:text class="mt-1 text-xs text-red-600">{{ $run->error_message }}</flux:text>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endunless

            </div>{{-- /center column --}}

            {{-- ── Right rail: decision actions + log + eligible approvers ──── --}}
            @unless ($editing)
                @php
                    $rrCurrent = $story->approvals->where('story_revision', $story->revision ?? 1)->sortBy('created_at')->values();
                    $rrPrior = $story->approvals->where('story_revision', '!=', $story->revision ?? 1)->sortByDesc('created_at')->values();
                    $rrPolicy = $this->effectivePolicy;
                    $rrEligibleVisible = ! $isTerminal
                        && $rrPolicy
                        && ($rrPolicy->required_approvals ?? 0) > 1
                        && in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true);
                    $rrEligible = $rrEligibleVisible ? $this->eligibleApprovers : collect();
                    $blockedNotice = $blockedBySelfApproval
                        && ! $autoPromotes
                        && in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true);
                    $decisionVisible = $hasAnyDecisionAction || $blockedNotice || $this->pendingPlanRun;
                    $showRail = $decisionVisible || $rrCurrent->isNotEmpty() || $rrPrior->isNotEmpty() || $rrEligible->isNotEmpty();
                @endphp
                @if ($showRail)
                    <aside class="flex w-full flex-col gap-4 lg:w-80 lg:flex-none" data-rail-aside>
                        @if ($decisionVisible)
                            <section data-section="decision" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Decision') }}</flux:text>

                                @if ($hasAnyDecisionAction)
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($hasDraftSubmit)
                                            <flux:button wire:click="submit" wire:target="submit" wire:loading.attr="disabled" variant="primary" class="w-full">
                                                <span wire:loading.remove wire:target="submit">{{ $autoPromotes ? __('Submit & generate plan') : __('Submit for approval') }}</span>
                                                <span wire:loading wire:target="submit">{{ __('Working…') }}</span>
                                            </flux:button>
                                        @endif

                                        @if ($hasAutoStart)
                                            <flux:button wire:click="startExecution" wire:target="startExecution" wire:loading.attr="disabled" variant="primary" class="w-full">
                                                <span wire:loading.remove wire:target="startExecution">{{ __('Start execution') }}</span>
                                                <span wire:loading wire:target="startExecution">{{ __('Working…') }}</span>
                                            </flux:button>
                                        @elseif ($hasApprovalActions)
                                            @if ($userApproved)
                                                <flux:button wire:click="decide('revoke')" wire:target="decide" wire:loading.attr="disabled" class="w-full">{{ __('Revoke approval') }}</flux:button>
                                            @else
                                                <flux:button wire:click="decide('approve')" wire:target="decide" wire:loading.attr="disabled" variant="primary" class="w-full">{{ __('Approve') }}</flux:button>
                                            @endif
                                            <flux:button wire:click="decide('changes_requested')" wire:target="decide" wire:loading.attr="disabled" class="w-full">{{ __('Request changes') }}</flux:button>
                                            <flux:button wire:click="decide('reject')" wire:target="decide" wire:loading.attr="disabled" variant="danger" class="w-full">{{ __('Reject') }}</flux:button>
                                        @endif

                                        @if ($hasResume)
                                            <flux:button wire:click="resumeExecution" wire:target="resumeExecution" wire:loading.attr="disabled" class="w-full">
                                                <span wire:loading.remove wire:target="resumeExecution">{{ __('Resume execution') }}</span>
                                                <span wire:loading wire:target="resumeExecution">{{ __('Working…') }}</span>
                                            </flux:button>
                                        @endif
                                    </div>
                                @endif

                                @if ($needsApprovalNote)
                                    <flux:textarea
                                        wire:model.defer="approvalNote"
                                        :placeholder="__('Notes (optional)')"
                                        rows="3"
                                    />
                                @endif

                                @if ($blockedNotice)
                                    <flux:text class="text-xs text-amber-600">
                                        {{ __('You authored this story; the policy disallows self-approval.') }}
                                    </flux:text>
                                @endif

                                @if ($this->pendingPlanRun)
                                    <flux:badge color="amber">{{ __('Generating plan…') }}</flux:badge>
                                @endif
                            </section>
                        @endif

                        @if ($rrCurrent->isNotEmpty() || $rrPrior->isNotEmpty())
                            <section data-section="decision-log" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Decision log') }}</flux:text>
                                @forelse ($rrCurrent as $approval)
                                    <x-decision-row :approval="$approval" />
                                @empty
                                    <flux:text class="text-xs text-zinc-500">{{ __('No decisions on this revision yet.') }}</flux:text>
                                @endforelse

                                @if ($rrPrior->isNotEmpty())
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Prior revisions') }} ({{ $rrPrior->count() }})</summary>
                                        <div class="mt-2 flex flex-col gap-2">
                                            @foreach ($rrPrior as $approval)
                                                <x-decision-row :approval="$approval" />
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </section>
                        @endif

                        @if ($rrEligible->isNotEmpty())
                            <section data-section="eligible-approvers" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Eligible approvers') }}</flux:text>
                                <ul class="text-sm">
                                    @foreach ($rrEligible as $u)
                                        <li class="py-0.5">{{ $u->name }}</li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </aside>
                @endif
            @endunless

        </div>{{-- /two-column wrapper --}}
    @endif
</div>
