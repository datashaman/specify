<?php

namespace App\Models;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Services\Plans\PlanApprovalLifecycle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['story_id', 'version', 'revision', 'name', 'summary', 'design_notes', 'implementation_notes', 'risks', 'assumptions', 'source', 'source_label', 'status'])]
class Plan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'revision' => 'integer',
            'source' => PlanSource::class,
            'status' => PlanStatus::class,
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PlanApproval::class);
    }

    public function effectivePolicy(): ApprovalPolicy
    {
        return $this->story?->effectivePolicy() ?? ApprovalPolicy::default();
    }

    public function isApproved(): bool
    {
        return $this->status === PlanStatus::Approved;
    }

    public function isCurrent(): bool
    {
        $this->loadMissing('story');

        return (int) $this->story?->current_plan_id === (int) $this->getKey();
    }

    public function submitForApproval(): void
    {
        app(PlanApprovalLifecycle::class)->submit($this);
    }

    public function reopenForApproval(): void
    {
        app(PlanApprovalLifecycle::class)->reopen($this);
    }
}
