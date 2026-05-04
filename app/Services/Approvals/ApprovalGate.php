<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalDecision;
use App\Models\ApprovalPolicy;
use App\Models\PlanApproval;
use App\Models\StoryApproval;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * Replays approval decisions and resolves the resulting gate status.
 */
class ApprovalGate
{
    /**
     * @param  Collection<int, StoryApproval|PlanApproval>  $approvals
     */
    public function nextStatus(
        Collection $approvals,
        ApprovalPolicy $policy,
        BackedEnum $currentStatus,
        ApprovalGateStatuses $statuses,
    ): ?BackedEnum {
        $state = $this->state($approvals);

        if ($state['rejected']) {
            return $statuses->rejected;
        }

        if ($state['changes_requested']) {
            return $statuses->changesRequested;
        }

        if ($policy->auto_approve || $state['count'] >= $policy->required_approvals) {
            if ($currentStatus !== $statuses->draft) {
                return $statuses->approved;
            }

            return null;
        }

        if ($currentStatus === $statuses->draft) {
            return null;
        }

        return $statuses->pending;
    }

    /**
     * @param  Collection<int, StoryApproval|PlanApproval>  $approvals
     * @return array{rejected: bool, changes_requested: bool, count: int}
     */
    private function state(Collection $approvals): array
    {
        if ($approvals->contains(fn ($approval) => $approval->decision === ApprovalDecision::Reject)) {
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
