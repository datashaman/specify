<?php

namespace App\Services\Plans;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Services\ApprovalService;
use RuntimeException;

class PlanApprovalLifecycle
{
    public function __construct(private ApprovalService $approvals) {}

    public function submit(Plan $plan): void
    {
        if ($plan->status === PlanStatus::Rejected) {
            throw new RuntimeException('Cannot submit a rejected plan.');
        }

        if (! $plan->tasks()->exists()) {
            throw new RuntimeException('Add at least one task before submitting a plan.');
        }

        $plan->forceFill(['status' => PlanStatus::PendingApproval->value])->save();

        $this->approvals->recomputePlan($plan->fresh());
    }

    public function reopen(Plan $plan): void
    {
        $nextStatus = match ($plan->status) {
            PlanStatus::Draft => PlanStatus::Draft,
            PlanStatus::Superseded => PlanStatus::Superseded,
            PlanStatus::Done => PlanStatus::Done,
            default => PlanStatus::PendingApproval,
        };

        $plan->forceFill([
            'revision' => ($plan->revision ?? 1) + 1,
            'status' => $nextStatus->value,
        ])->save();

        if ($nextStatus === PlanStatus::Draft || $nextStatus === PlanStatus::Superseded || $nextStatus === PlanStatus::Done) {
            return;
        }

        $this->approvals->recomputePlan($plan->fresh());
    }
}
