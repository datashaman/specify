<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\ApprovalPolicy;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

class ApprovalService
{
    public function recordDecision(
        Story|Plan $target,
        User $approver,
        ApprovalDecision $decision,
        ?string $notes = null,
    ): StoryApproval|PlanApproval {
        if ($target instanceof Story && $target->status === StoryStatus::Rejected) {
            throw new RuntimeException('Story is rejected; no further decisions accepted.');
        }
        if ($target instanceof Plan && $target->status === PlanStatus::Rejected) {
            throw new RuntimeException('Plan is rejected; no further decisions accepted.');
        }

        $policy = $target->effectivePolicy();

        if (
            $decision === ApprovalDecision::Approve
            && ! $policy->allow_self_approval
            && $target instanceof Story
            && $target->created_by_id !== null
            && (int) $target->created_by_id === (int) $approver->getKey()
        ) {
            throw new InvalidArgumentException('Self-approval not permitted by policy.');
        }

        $approval = $target instanceof Story
            ? StoryApproval::create([
                'story_id' => $target->getKey(),
                'story_revision' => $target->revision ?? 1,
                'approver_id' => $approver->getKey(),
                'decision' => $decision->value,
                'notes' => $notes,
            ])
            : PlanApproval::create([
                'plan_id' => $target->getKey(),
                'plan_revision' => 0,
                'approver_id' => $approver->getKey(),
                'decision' => $decision->value,
                'notes' => $notes,
            ]);

        $this->recompute($target);

        return $approval;
    }

    public function recompute(Story|Plan $target): void
    {
        $policy = $target->effectivePolicy();

        if ($target instanceof Story) {
            $this->recomputeStory($target, $policy);

            return;
        }

        $this->recomputePlan($target, $policy);
    }

    private function recomputeStory(Story $story, ApprovalPolicy $policy): void
    {
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
            } else {
                // Story still in draft: leave alone — submission promotes it.
            }

            return;
        }

        if ($story->status === StoryStatus::Draft) {
            return;
        }

        $story->forceFill(['status' => StoryStatus::PendingApproval->value])->save();
    }

    private function recomputePlan(Plan $plan, ApprovalPolicy $policy): void
    {
        $approvals = $plan->approvals()
            ->orderBy('created_at')
            ->get();

        if ($approvals->contains(fn ($a) => $a->decision === ApprovalDecision::Reject)) {
            $plan->forceFill(['status' => PlanStatus::Rejected->value])->save();

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
            $plan->forceFill(['status' => PlanStatus::ChangesRequested->value])->save();

            return;
        }

        $count = count($effective);

        if ($policy->auto_approve || $count >= $policy->required_approvals) {
            if ($plan->status !== PlanStatus::Draft) {
                $plan->forceFill(['status' => PlanStatus::Approved->value])->save();
                app(ExecutionService::class)->startPlanExecution($plan->fresh());
            }

            return;
        }

        if ($plan->status === PlanStatus::Draft) {
            return;
        }

        $plan->forceFill(['status' => PlanStatus::PendingApproval->value])->save();
    }
}
