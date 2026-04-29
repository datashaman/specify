<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Executing = 'executing';
    case Done = 'done';
}
