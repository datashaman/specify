<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
    case Done = 'done';
}
