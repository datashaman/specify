<?php

namespace App\Services\Approvals;

use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use BackedEnum;

/**
 * Status vocabulary for one approval gate target.
 */
readonly class ApprovalGateStatuses
{
    public function __construct(
        public BackedEnum $draft,
        public BackedEnum $pending,
        public BackedEnum $approved,
        public BackedEnum $changesRequested,
        public BackedEnum $rejected,
    ) {}

    public static function story(): self
    {
        return new self(
            draft: StoryStatus::Draft,
            pending: StoryStatus::PendingApproval,
            approved: StoryStatus::Approved,
            changesRequested: StoryStatus::ChangesRequested,
            rejected: StoryStatus::Rejected,
        );
    }

    public static function plan(): self
    {
        return new self(
            draft: PlanStatus::Draft,
            pending: PlanStatus::PendingApproval,
            approved: PlanStatus::Approved,
            changesRequested: PlanStatus::PendingApproval,
            rejected: PlanStatus::Rejected,
        );
    }
}
