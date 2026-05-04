<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
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

    public ?string $planApprovalNote = null;

    public bool $editing = false;

    #[Validate('required|string|max:255')]
    public string $editName = '';

    #[Validate('required|string')]
    public string $editDescription = '';

    /** @var array<int, array{id: ?int, statement: string}> */
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
            ->map(fn (AcceptanceCriterion $ac) => ['id' => $ac->id, 'statement' => (string) $ac->statement])
            ->all();
        if ($this->editCriteria === []) {
            $this->editCriteria = [['id' => null, 'statement' => '']];
        }
        $this->editing = true;
    }

    public function addCriterion(): void
    {
        $this->editCriteria[] = ['id' => null, 'statement' => ''];
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
            'editCriteria.*.statement' => 'required|string|max:1000',
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
                $text = trim((string) ($row['statement'] ?? ''));
                $id = $row['id'] ?? null;

                if ($id !== null && $existing->has($id)) {
                    $ac = $existing[$id];
                    if ($ac->statement !== $text || $ac->position !== $position) {
                        $ac->update(['statement' => $text, 'position' => $position]);
                        $changed = true;
                    }
                    $kept[] = $id;
                } else {
                    AcceptanceCriterion::create([
                        'story_id' => $story->id,
                        'position' => $position,
                        'statement' => $text,
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
        if ($fresh->status === StoryStatus::Approved && ! $fresh->currentPlanTasks()->exists()) {
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

    public function submitPlan(): void
    {
        $story = $this->story;
        abort_unless($story && $story->currentPlan, 404);
        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        $story->currentPlan->submitForApproval();
        unset($this->story);
    }

    public function decidePlan(string $decision): void
    {
        $story = $this->story;
        abort_unless($story && $story->currentPlan, 404);
        $user = Auth::user();
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        app(ApprovalService::class)->recordPlanDecision(
            $story->currentPlan,
            $user,
            ApprovalDecision::from($decision),
            $this->planApprovalNote ?: null,
        );

        $this->planApprovalNote = null;
        unset($this->story);
    }

    public function generatePlan(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($story->status === StoryStatus::Approved, 422, 'Story must be Approved.');
        abort_if($story->currentPlanTasks()->exists(), 422, 'Plan already exists.');
        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        app(ExecutionService::class)->dispatchTaskGeneration($story);

        unset($this->story);
    }

    public function resolveConflicts(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        $result = app(ExecutionService::class)->dispatchConflictResolution($story);

        match ($result['status']) {
            'dispatched' => session()->flash('conflict_resolution', __('AI conflict-resolution run queued.')),
            'max_cycles_reached' => session()->flash('conflict_resolution_error', __('Maximum AI conflict-resolution attempts reached for this pull request.')),
            default => session()->flash('conflict_resolution_error', __('Could not start conflict resolution.')),
        };

        unset($this->story);
    }

    public function resumeExecution(): void
    {
        $story = $this->story;
        abort_unless($story, 404);
        abort_unless($story->status === StoryStatus::Approved, 422, 'Story must be Approved.');
        abort_unless(Auth::user()->canApproveInProject($story->feature->project), 403);

        $subtaskIds = Subtask::whereIn('task_id', $story->currentPlanTasks()->pluck('id'))->pluck('id');

        AgentRun::where('runnable_type', Subtask::class)
            ->whereIn('runnable_id', $subtaskIds)
            ->active()
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
        abort_unless($story->currentPlanTasks()->exists(), 422, 'No plan to execute.');

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
        return (bool) $this->story?->hasActiveSubtaskRun();
    }

    #[Computed]
    public function activeConflictResolutionRun(): ?AgentRun
    {
        return $this->story?->activeConflictResolutionAgentRun();
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
            $text = trim((string) ($row['statement'] ?? ''));
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
            if (trim((string) $existing[$id]->statement) !== $text) {
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
            ->active()
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
                'scenarios.acceptanceCriterion',
                'currentPlan.approvals.approver',
                'currentPlanTasks.plan',
                'currentPlanTasks.acceptanceCriterion',
                'currentPlanTasks.scenario',
                'currentPlanTasks.dependencies',
                'currentPlanTasks.subtasks.agentRuns.repo',
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
    public function effectivePlanApprovals(): array
    {
        $plan = $this->story?->currentPlan;
        if (! $plan) {
            return [];
        }

        $effective = [];
        foreach ($plan->approvals->where('plan_revision', $plan->revision ?? 1)->sortBy('created_at') as $a) {
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
    public function userApprovedPlan(): bool
    {
        return isset($this->effectivePlanApprovals[Auth::id()]);
    }

    #[Computed]
    public function planPill(): array
    {
        $plan = $this->story?->currentPlan;
        if (! $plan) {
            return ['state' => 'draft', 'tally' => null, 'label' => __('No current plan')];
        }

        $policy = $this->effectivePolicy;
        $required = $policy?->required_approvals ?? 0;
        $count = count($this->effectivePlanApprovals);

        return match ($plan->status) {
            PlanStatus::Draft => ['state' => 'draft', 'tally' => null, 'label' => __('Draft')],
            PlanStatus::PendingApproval => [
                'state' => 'pending',
                'tally' => $required > 0 ? sprintf('%d/%d', $count, $required) : null,
                'label' => __('Pending'),
            ],
            PlanStatus::Approved => [
                'state' => 'approved',
                'tally' => $required > 0 ? sprintf('%d/%d', $required, $required) : null,
                'label' => __('Approved'),
            ],
            PlanStatus::Rejected => ['state' => 'rejected', 'tally' => null, 'label' => __('Rejected')],
            PlanStatus::Superseded => ['state' => 'changes_requested', 'tally' => null, 'label' => __('Superseded')],
            PlanStatus::Done => ['state' => 'run_complete', 'tally' => null, 'label' => __('Done')],
        };
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
    public function planBlockedBySelfApproval(): bool
    {
        return $this->story?->currentPlan !== null && $this->blockedBySelfApproval;
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

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function planViewData(): array
    {
        $story = $this->story;
        if (! $story) {
            return [];
        }

        $tasksByAc = $story->currentPlanTasks->groupBy('acceptance_criterion_id');
        $latestRun = $story->currentPlanTasks->flatMap->subtasks->flatMap->agentRuns->sortByDesc('id')->first();

        return [
            'story' => $story,
            'tasksByAc' => $tasksByAc,
            'unmappedTasks' => $tasksByAc->get(null, collect())->sortBy('position')->values(),
            'acs' => $story->acceptanceCriteria->sortBy('position')->values(),
            'subtaskCount' => $story->currentPlanTasks->reduce(fn ($acc, $task) => $acc + $task->subtasks->count(), 0),
            'shouldRunMode' => $this->hasActiveSubtaskRun,
            'branch' => $latestRun?->working_branch,
            'repo' => $latestRun?->repo,
            'planGenRuns' => $this->planGenerationRuns,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function decisionRailViewData(): array
    {
        $story = $this->story;
        if (! $story) {
            return [];
        }

        $currentPlan = $story->currentPlan;
        $policy = $this->effectivePolicy;
        $canApprove = $this->canApproveStory;
        $canApprovePlan = $currentPlan !== null && $canApprove;
        $isAuthor = $this->isAuthor;
        $blockedBySelfApproval = $this->blockedBySelfApproval;
        $planBlockedBySelfApproval = $this->planBlockedBySelfApproval;
        $autoPromotes = $this->autoPromotes;
        $hasIncompleteWork = $story->status === StoryStatus::Approved
            && $story->currentPlanTasks->isNotEmpty()
            && $story->currentPlanTasks->flatMap->subtasks->contains(fn ($s) => $s->status !== TaskStatus::Done);
        $needsApprovalNote = in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true)
            && $canApprove
            && ! $blockedBySelfApproval
            && ! $autoPromotes;
        $needsPlanApprovalNote = $currentPlan !== null
            && in_array($currentPlan->status, [PlanStatus::PendingApproval], true)
            && $canApprovePlan
            && ! $planBlockedBySelfApproval
            && ! $autoPromotes;
        $hasDraftSubmit = $story->status === StoryStatus::Draft && ($isAuthor || $canApprove);
        $hasAutoStart = $story->status === StoryStatus::PendingApproval && $story->currentPlanTasks->isNotEmpty() && $autoPromotes && $canApprove;
        $hasApprovalActions = in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true) && $canApprove && ! $blockedBySelfApproval;
        $hasPlanSubmit = $currentPlan !== null && $currentPlan->status === PlanStatus::Draft && $story->currentPlanTasks->isNotEmpty() && $canApprovePlan;
        $hasPlanApprovalActions = $currentPlan !== null && $currentPlan->status === PlanStatus::PendingApproval && $canApprovePlan && ! $planBlockedBySelfApproval;
        $hasResume = $hasIncompleteWork && $canApprove;
        $hasStartExecution = $story->status === StoryStatus::Approved
            && $currentPlan?->status === PlanStatus::Approved
            && $story->currentPlanTasks->isNotEmpty()
            && ! $this->hasActiveSubtaskRun
            && $canApprove;
        $hasAnyDecisionAction = $hasDraftSubmit || $hasAutoStart || $hasApprovalActions || $hasPlanSubmit || $hasPlanApprovalActions || $hasResume || $hasStartExecution;
        $isTerminal = in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true);
        $rrCurrent = $story->approvals->where('story_revision', $story->revision ?? 1)->sortBy('created_at')->values();
        $rrPrior = $story->approvals->where('story_revision', '!=', $story->revision ?? 1)->sortByDesc('created_at')->values();
        $planCurrent = $currentPlan?->approvals?->where('plan_revision', $currentPlan->revision ?? 1)?->sortBy('created_at')?->values() ?? collect();
        $planPrior = $currentPlan?->approvals?->where('plan_revision', '!=', $currentPlan->revision ?? 1)?->sortByDesc('created_at')?->values() ?? collect();
        $rrEligibleVisible = ! $isTerminal
            && $policy
            && ($policy->required_approvals ?? 0) > 1
            && (in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true)
                || ($currentPlan && in_array($currentPlan->status, [PlanStatus::PendingApproval], true)));
        $rrEligible = $rrEligibleVisible ? $this->eligibleApprovers : collect();
        $blockedNotice = $blockedBySelfApproval
            && ! $autoPromotes
            && in_array($story->status, [StoryStatus::PendingApproval, StoryStatus::ChangesRequested], true);
        $planBlockedNotice = $planBlockedBySelfApproval
            && ! $autoPromotes
            && $currentPlan
            && in_array($currentPlan->status, [PlanStatus::PendingApproval], true);
        $decisionVisible = $hasAnyDecisionAction || $blockedNotice || $planBlockedNotice || $this->pendingPlanRun;

        return [
            'showRail' => $decisionVisible || $rrCurrent->isNotEmpty() || $rrPrior->isNotEmpty() || $planCurrent->isNotEmpty() || $planPrior->isNotEmpty() || $rrEligible->isNotEmpty(),
            'decisionVisible' => $decisionVisible,
            'hasAnyDecisionAction' => $hasAnyDecisionAction,
            'hasDraftSubmit' => $hasDraftSubmit,
            'autoPromotes' => $autoPromotes,
            'hasAutoStart' => $hasAutoStart,
            'hasApprovalActions' => $hasApprovalActions,
            'hasPlanSubmit' => $hasPlanSubmit,
            'hasPlanApprovalActions' => $hasPlanApprovalActions,
            'hasStartExecution' => $hasStartExecution,
            'userApproved' => $this->userApproved,
            'userApprovedPlan' => $this->userApprovedPlan,
            'hasResume' => $hasResume,
            'needsApprovalNote' => $needsApprovalNote,
            'needsPlanApprovalNote' => $needsPlanApprovalNote,
            'blockedNotice' => $blockedNotice,
            'planBlockedNotice' => $planBlockedNotice,
            'rrCurrent' => $rrCurrent,
            'rrPrior' => $rrPrior,
            'planCurrent' => $planCurrent,
            'planPrior' => $planPrior,
            'rrEligible' => $rrEligible,
            'pill' => $this->pill,
            'planPill' => $this->planPill,
            'currentPlan' => $currentPlan,
        ];
    }
}; ?>

<div
    class="flex p-6"
    @if ($this->pendingPlanRun || $this->activeConflictResolutionRun) wire:poll.3s @endif
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
            $planPill = $this->planPill;
        @endphp

        <x-rail :state="$this->railState" class="mr-4" />

        <div class="flex min-w-0 flex-1 flex-col gap-6 lg:flex-row">
            <div class="flex min-w-0 max-w-4xl flex-1 flex-col gap-6">

        @include('partials.story-show.flash')

        {{-- ── Header ──────────────────────────────────────────── --}}
        <div>
            @include('partials.story-show.breadcrumb', ['story' => $story, 'project' => $project])

            @if ($editing)
                @include('partials.story-show.edit-form')
            @else
                @php $storyPrs = $story->pullRequests(); @endphp
                @include('partials.story-show.header', ['story' => $story, 'pill' => $pill, 'planPill' => $planPill, 'storyPrs' => $storyPrs])
            @endif
        </div>

        @unless ($editing)
            {{-- ── Story body: framing + description + notes ── --}}
            @include('partials.story-show.contract', ['story' => $story, 'planPill' => $planPill])
        @endunless

        @unless ($editing)
            {{-- ── Plan: ACs → Tasks → Subtasks → runs (AC-led) ────────────── --}}
            @include('partials.story-show.plan', $this->planViewData)
        @endunless

            </div>{{-- /center column --}}

            {{-- ── Right rail: decision actions + log + eligible approvers ──── --}}
            @unless ($editing)
                @include('partials.story-show.decision-rail', $this->decisionRailViewData)
            @endunless

        </div>{{-- /two-column wrapper --}}
    @endif
</div>
