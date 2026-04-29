<?php

namespace App\Enums;

enum ApprovalDecision: string
{
    case Approve = 'approve';
    case ChangesRequested = 'changes_requested';
    case Reject = 'reject';
    case Revoke = 'revoke';
}
