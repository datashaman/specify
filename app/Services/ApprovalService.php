<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\User;
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

        $policy = $target->effectivePolicy();

        if (
            $decision === ApprovalDecision::Approve
            && ! $policy->allow_self_approval
            && $target->created_by_id !== null
            && (int) $target->created_by_id === (int) $approver->getKey()
        ) {
            throw new InvalidArgumentException('Self-approval not permitted by policy.');
        }

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

        $approvals = $story->approvals()
            ->where('story_revision', $story->revision ?? 1)
            ->orderBy('created_at')
            ->get();

        if ($approvals->contains(fn ($a) => $a->decision === ApprovalDecision::Reject)) {
            $story->forceFill(['status' => StoryStatus::Rejected->value])->save();

            return;
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
            }
        }

        if ($changesRequested) {
            $story->forceFill(['status' => StoryStatus::ChangesRequested->value])->save();

            return;
        }

        $count = count($effective);

        if ($policy->auto_approve || $count >= $policy->required_approvals) {
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
}
