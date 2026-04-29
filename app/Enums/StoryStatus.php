<?php

namespace App\Enums;

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
