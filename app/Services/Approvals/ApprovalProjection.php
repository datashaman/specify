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
            ->sortBy(fn ($a) => [$a->created_at?->getTimestamp(), (int) $a->id])
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
            ->sortByDesc(fn ($a) => [$a->created_at?->getTimestamp(), (int) $a->id])
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
            ->sortBy(fn ($a) => [$a->created_at?->getTimestamp(), (int) $a->id])
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
            ->sortByDesc(fn ($a) => [$a->created_at?->getTimestamp(), (int) $a->id])
            ->values();
    }

    /**
     * Mirrors {@see ApprovalGate::state()}: ChangesRequested clears the
     * effective set, Reject is terminal (no effective approvers), Approve
     * sets the row, Revoke clears that approver. Sort is (created_at, id)
     * so same-second decisions remain deterministic.
     */
    private function replay(Collection $approvals, string $revisionColumn, int $revision): array
    {
        $rows = $approvals
            ->where($revisionColumn, $revision)
            ->sortBy(fn ($a) => [$a->created_at?->getTimestamp(), (int) $a->id]);

        if ($rows->contains(fn ($a) => $a->decision === ApprovalDecision::Reject)) {
            return [];
        }

        $effective = [];
        foreach ($rows as $approval) {
            $key = (int) $approval->approver_id;
            switch ($approval->decision) {
                case ApprovalDecision::Approve:
                    $effective[$key] = $approval;
                    break;
                case ApprovalDecision::Revoke:
                    unset($effective[$key]);
                    break;
                case ApprovalDecision::ChangesRequested:
                    $effective = [];
                    break;
            }
        }

        return $effective;
    }
}
