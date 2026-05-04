<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\User;
use App\Services\Approvals\ApprovalGate;
use App\Services\Approvals\ApprovalGateStatuses;
use InvalidArgumentException;
use RuntimeException;

/**
 * Coordinates Story approval state.
 *
 * Records decisions against the current `story_revision` and re-runs the
 * state machine. Uniqueness is per (approver, revision); editing a story
 * bumps revision, which discards old approvals from the count.
 */
class ApprovalService
{
    public function __construct(private ApprovalGate $gate) {}

    /**
     * Record an approval decision against the story's current revision and recompute status.
     *
     * Rejects further decisions on a Rejected story and enforces the policy's
     * `allow_self_approval` flag for the story's creator.
     *
     * @throws RuntimeException When the story is already Rejected.
     * @throws InvalidArgumentException When self-approval is attempted but not allowed.
     */
    public function recordDecision(
        Story $target,
        User $approver,
        ApprovalDecision $decision,
        ?string $notes = null,
    ): StoryApproval {
        if ($target->status === StoryStatus::Rejected) {
            throw new RuntimeException('Story is rejected; no further decisions accepted.');
        }

        $this->guardSelfApproval($target->effectivePolicy()->allow_self_approval, $target->created_by_id, $approver, $decision);

        $approval = StoryApproval::create([
            'story_id' => $target->getKey(),
            'story_revision' => $target->revision ?? 1,
            'approver_id' => $approver->getKey(),
            'decision' => $decision->value,
            'notes' => $notes,
        ]);

        $this->recompute($target);

        return $approval;
    }

    /**
     * Record an approval decision against the plan's current revision and recompute status.
     *
     * @throws RuntimeException When the plan is already Rejected or Superseded.
     * @throws InvalidArgumentException When self-approval is attempted but not allowed.
     */
    public function recordPlanDecision(
        Plan $target,
        User $approver,
        ApprovalDecision $decision,
        ?string $notes = null,
    ): PlanApproval {
        if (! $target->isCurrent()) {
            throw new RuntimeException('Only the story\'s current plan can receive approval decisions.');
        }
        if ($target->status === PlanStatus::Draft) {
            throw new RuntimeException('Plan must be submitted before review decisions can be recorded.');
        }
        if ($target->status === PlanStatus::Rejected) {
            throw new RuntimeException('Plan is rejected; no further decisions accepted.');
        }
        if ($target->status === PlanStatus::Superseded) {
            throw new RuntimeException('Plan is superseded; no further decisions accepted.');
        }

        $this->guardSelfApproval($target->effectivePolicy()->allow_self_approval, $target->story?->created_by_id, $approver, $decision);

        $approval = PlanApproval::create([
            'plan_id' => $target->getKey(),
            'plan_revision' => $target->revision ?? 1,
            'approver_id' => $approver->getKey(),
            'decision' => $decision->value,
            'notes' => $notes,
        ]);

        $this->recomputePlan($target);

        return $approval;
    }

    /**
     * Replay approvals for the current revision and write the resulting Story status.
     *
     * Reject is terminal and short-circuits. ChangesRequested resets effective
     * approvals to empty. Approve/Revoke maintain a per-approver tally; once
     * the unique-approver count meets `required_approvals` (or `auto_approve`
     * is set), the story moves to Approved. Drafts are not auto-promoted.
     */
    public function recompute(Story $story): void
    {
        $next = $this->gate->nextStatus(
            approvals: $story->approvals()->where('story_revision', $story->revision ?? 1)->orderBy('created_at')->get(),
            policy: $story->effectivePolicy(),
            currentStatus: $story->status,
            statuses: ApprovalGateStatuses::story(),
        );

        if ($next !== null) {
            $story->forceFill(['status' => $next->value])->save();
        }
    }

    public function recomputePlan(Plan $plan): void
    {
        $next = $this->gate->nextStatus(
            approvals: $plan->approvals()->where('plan_revision', $plan->revision ?? 1)->orderBy('created_at')->get(),
            policy: $plan->effectivePolicy(),
            currentStatus: $plan->status,
            statuses: ApprovalGateStatuses::plan(),
        );

        if ($next !== null) {
            $plan->forceFill(['status' => $next->value])->save();
        }
    }

    private function guardSelfApproval(bool $allowSelfApproval, ?int $creatorId, User $approver, ApprovalDecision $decision): void
    {
        if (
            $decision === ApprovalDecision::Approve
            && ! $allowSelfApproval
            && $creatorId !== null
            && $creatorId === (int) $approver->getKey()
        ) {
            throw new InvalidArgumentException('Self-approval not permitted by policy.');
        }
    }
}
