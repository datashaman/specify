<?php

namespace App\Enums;

/**
 * Decision recorded against a StoryApproval or PlanApproval row.
 *
 * Approve counts toward the threshold (unique per approver), Reject is terminal,
 * ChangesRequested resets back to Draft-equivalent, Revoke cancels a prior decision.
 */
enum ApprovalDecision: string
{
    case Approve = 'approve';
    case ChangesRequested = 'changes_requested';
    case Reject = 'reject';
    case Revoke = 'revoke';
}
