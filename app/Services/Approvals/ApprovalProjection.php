<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalDecision;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\Story;
use App\Models\StoryApproval;
use Illuminate\Support\Collection;

/**
 * Derived projections over StoryApproval / PlanApproval audit logs.
 *
 * StoryApproval and PlanApproval rows are immutable (ADR-0001). Display
 * surfaces need a per-revision, per-approver replay; this lives here so it
 * isn't reimplemented in every Volt page.
 */
class ApprovalProjection
{
    /**
     * Live approvers for the story's current revision (replayed approve/revoke).
     *
     * @return array<int, StoryApproval>
     */
    public function effectiveStoryApprovals(Story $story): array
    {
        return $this->replay($story->approvals, 'story_revision', $story->revision ?? 1);
    }

    /**
     * Live approvers for the plan's current revision (replayed approve/revoke).
     *
     * @return array<int, PlanApproval>
     */
    public function effectivePlanApprovals(Plan $plan): array
    {
        return $this->replay($plan->approvals, 'plan_revision', $plan->revision ?? 1);
    }

    /**
     * Approvals on the story's current revision, ordered oldest first.
     *
     * @return Collection<int, StoryApproval>
     */
    public function currentRevisionStoryApprovals(Story $story): Collection
    {
        return $story->approvals
            ->where('story_revision', $story->revision ?? 1)
            ->sortBy('created_at')
            ->values();
    }

    /**
     * Approvals on prior story revisions, ordered newest first.
     *
     * @return Collection<int, StoryApproval>
     */
    public function priorRevisionStoryApprovals(Story $story): Collection
    {
        return $story->approvals
            ->where('story_revision', '!=', $story->revision ?? 1)
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * Approvals on the plan's current revision, ordered oldest first.
     *
     * @return Collection<int, PlanApproval>
     */
    public function currentRevisionPlanApprovals(Plan $plan): Collection
    {
        return $plan->approvals
            ->where('plan_revision', $plan->revision ?? 1)
            ->sortBy('created_at')
            ->values();
    }

    /**
     * Approvals on prior plan revisions, ordered newest first.
     *
     * @return Collection<int, PlanApproval>
     */
    public function priorRevisionPlanApprovals(Plan $plan): Collection
    {
        return $plan->approvals
            ->where('plan_revision', '!=', $plan->revision ?? 1)
            ->sortByDesc('created_at')
            ->values();
    }

    private function replay(Collection $approvals, string $revisionColumn, int $revision): array
    {
        $effective = [];
        foreach ($approvals->where($revisionColumn, $revision)->sortBy('created_at') as $approval) {
            $key = (int) $approval->approver_id;
            if ($approval->decision === ApprovalDecision::Approve) {
                $effective[$key] = $approval;
            } elseif ($approval->decision === ApprovalDecision::Revoke) {
                unset($effective[$key]);
            }
        }

        return $effective;
    }
}
