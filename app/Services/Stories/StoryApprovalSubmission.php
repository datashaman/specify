<?php

namespace App\Services\Stories;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\ApprovalService;
use RuntimeException;

class StoryApprovalSubmission
{
    public function __construct(private ApprovalService $approvals) {}

    public function submit(Story $story): void
    {
        if ($story->status === StoryStatus::Rejected) {
            throw new RuntimeException('Cannot submit a rejected story.');
        }

        if (! $story->acceptanceCriteria()->exists()) {
            throw new RuntimeException('Add at least one acceptance criterion before submitting.');
        }

        $story->forceFill(['status' => StoryStatus::PendingApproval])->save();

        $this->approvals->recompute($story);
    }
}
