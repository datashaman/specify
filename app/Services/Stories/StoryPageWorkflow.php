<?php

namespace App\Services\Stories;

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ExecutionService;

class StoryPageWorkflow
{
    public function __construct(
        private ApprovalService $approvals,
        private ExecutionService $execution,
    ) {}

    public function submitStory(Story $story, User $user): void
    {
        abort_unless($story->status === StoryStatus::Draft, 422, 'Story is not a draft.');
        abort_unless($story->created_by_id === $user->getKey() || $user->canApproveInProject($story->feature->project), 403);

        $story->submitForApproval();

        $fresh = $story->fresh();
        if ($fresh->status === StoryStatus::Approved && ! $fresh->currentPlanTasks()->exists()) {
            $this->execution->dispatchTaskGeneration($fresh);
        }
    }

    public function recordStoryDecision(Story $story, User $user, ApprovalDecision $decision, ?string $note = null): void
    {
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        $this->approvals->recordDecision($story, $user, $decision, $note);
    }

    public function submitCurrentPlan(Story $story, User $user): void
    {
        abort_unless($story->currentPlan, 404);
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        $story->currentPlan->submitForApproval();
    }

    public function recordPlanDecision(Story $story, User $user, ApprovalDecision $decision, ?string $note = null): void
    {
        abort_unless($story->currentPlan, 404);
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        $this->approvals->recordPlanDecision($story->currentPlan, $user, $decision, $note);
    }

    public function generateCurrentPlan(Story $story, User $user): void
    {
        abort_unless($story->status === StoryStatus::Approved, 422, 'Story must be Approved.');
        abort_if($story->currentPlanTasks()->exists(), 422, 'Plan already exists.');
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        $this->execution->dispatchTaskGeneration($story);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConflicts(Story $story, User $user): array
    {
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        return $this->execution->dispatchConflictResolution($story);
    }

    public function resumeExecution(Story $story, User $user): void
    {
        abort_unless($story->status === StoryStatus::Approved, 422, 'Story must be Approved.');
        abort_unless($user->canApproveInProject($story->feature->project), 403);

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

        $this->execution->startStoryExecution($story->fresh());
    }

    public function autoApproveStoryContract(Story $story, User $user): void
    {
        abort_unless($story->status === StoryStatus::PendingApproval, 422, 'Story is not awaiting approval.');
        abort_unless($story->currentPlanTasks()->exists(), 422, 'No plan to execute.');

        $policy = $story->effectivePolicy();
        abort_unless($policy->auto_approve || $policy->required_approvals === 0, 403, 'Policy requires explicit approvals.');
        abort_unless($user->canApproveInProject($story->feature->project), 403);

        $this->approvals->recompute($story);
    }
}
