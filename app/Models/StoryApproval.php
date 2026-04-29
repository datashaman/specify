<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable(['story_id', 'story_revision', 'approver_id', 'decision', 'notes'])]
class StoryApproval extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! $model->created_at) {
                $model->created_at = now();
            }
        });

        static::updating(fn () => throw new RuntimeException('Story approvals are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Story approvals are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecision::class,
            'created_at' => 'datetime',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
