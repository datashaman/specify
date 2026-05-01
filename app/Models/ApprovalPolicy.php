<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Approval rule attached to a Workspace, Project, or Story (cascade).
 *
 * `Story::effectivePolicy()` walks up Story → Project → Workspace and falls
 * back to `default()`. Holds the threshold (`required_approvals`) and
 * the `allow_self_approval` / `auto_approve` flags ApprovalService consults.
 */
#[Fillable(['scope_type', 'scope_id', 'required_approvals', 'allow_self_approval', 'auto_approve', 'notes'])]
class ApprovalPolicy extends Model
{
    public const SCOPE_WORKSPACE = 'workspace';

    public const SCOPE_PROJECT = 'project';

    public const SCOPE_STORY = 'story';

    public const SCOPE_PLAN = 'plan';

    protected function casts(): array
    {
        return [
            'required_approvals' => 'integer',
            'allow_self_approval' => 'boolean',
            'auto_approve' => 'boolean',
        ];
    }

    public static function default(): self
    {
        return new self([
            'required_approvals' => 0,
            'allow_self_approval' => false,
            'auto_approve' => false,
        ]);
    }
}
