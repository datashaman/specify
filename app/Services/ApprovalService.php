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
use Illuminate\Support\Collection;
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
        $policy = $story->effectivePolicy();
        $state = $this->approvalState(
            $story->approvals()->where('story_revision', $story->revision ?? 1)->orderBy('created_at')->get()
        );

        if ($state['rejected']) {
            $story->forceFill(['status' => StoryStatus::Rejected->value])->save();

            return;
        }

        if ($state['changes_requested']) {
            $story->forceFill(['status' => StoryStatus::ChangesRequested->value])->save();

            return;
        }

        if ($policy->auto_approve || $state['count'] >= $policy->required_approvals) {
            if ($story->status !== StoryStatus::Draft) {
                $story->forceFill(['status' => StoryStatus::Approved->value])->save();
            }

            return;
        }

        if ($story->status === StoryStatus::Draft) {
            return;
        }

        $story->forceFill(['status' => StoryStatus::PendingApproval->value])->save();
    }

    public function recomputePlan(Plan $plan): void
    {
        $policy = $plan->effectivePolicy();
        $state = $this->approvalState(
            $plan->approvals()->where('plan_revision', $plan->revision ?? 1)->orderBy('created_at')->get()
        );

        if ($state['rejected']) {
            $plan->forceFill(['status' => PlanStatus::Rejected->value])->save();

            return;
        }

        if ($state['changes_requested']) {
            $plan->forceFill(['status' => PlanStatus::PendingApproval->value])->save();

            return;
        }

        if ($policy->auto_approve || $state['count'] >= $policy->required_approvals) {
            if ($plan->status !== PlanStatus::Draft) {
                $plan->forceFill(['status' => PlanStatus::Approved->value])->save();
            }

            return;
        }

        if ($plan->status === PlanStatus::Draft) {
            return;
        }

        $plan->forceFill(['status' => PlanStatus::PendingApproval->value])->save();
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

    /**
     * @param  Collection<int, StoryApproval|PlanApproval>  $approvals
     * @return array{rejected: bool, changes_requested: bool, count: int}
     */
    private function approvalState(Collection $approvals): array
    {
        if ($approvals->contains(fn ($a) => $a->decision === ApprovalDecision::Reject)) {
            return ['rejected' => true, 'changes_requested' => false, 'count' => 0];
        }

        $effective = [];
        $changesRequested = false;

        foreach ($approvals as $approval) {
            $approverKey = (int) $approval->approver_id;

            switch ($approval->decision) {
                case ApprovalDecision::Approve:
                    $effective[$approverKey] = true;
                    $changesRequested = false;
                    break;
                case ApprovalDecision::Revoke:
                    unset($effective[$approverKey]);
                    break;
                case ApprovalDecision::ChangesRequested:
                    $effective = [];
                    $changesRequested = true;
                    break;
                case ApprovalDecision::Reject:
                    break;
            }
        }

        return [
            'rejected' => false,
            'changes_requested' => $changesRequested,
            'count' => count($effective),
        ];
    }
}
