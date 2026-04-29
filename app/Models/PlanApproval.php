<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable(['plan_id', 'plan_revision', 'approver_id', 'decision', 'notes'])]
class PlanApproval extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! $model->created_at) {
                $model->created_at = now();
            }
        });

        static::updating(fn () => throw new RuntimeException('Plan approvals are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Plan approvals are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecision::class,
            'created_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
