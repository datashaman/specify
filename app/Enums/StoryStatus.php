<?php

namespace App\Enums;

/**
 * Lifecycle of a Story from drafting through approval to completion.
 *
 * Approval-related transitions (PendingApproval → Approved/ChangesRequested/Rejected)
 * are driven by ApprovalService; terminal states are Done, Rejected, and Cancelled.
 */
enum StoryStatus: string
{
    case Draft = 'draft';
    case ProposedByAI = 'proposed_by_ai';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
