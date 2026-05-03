<?php

namespace App\Models;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Models\ApprovalPolicy;
use App\Services\ApprovalService;
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

    public function submitForApproval(): void
    {
        if ($this->status === PlanStatus::Rejected) {
            throw new \RuntimeException('Cannot submit a rejected plan.');
        }
        if ($this->tasks()->count() === 0) {
            throw new \RuntimeException('Add at least one task before submitting a plan.');
        }

        $this->forceFill(['status' => PlanStatus::PendingApproval->value])->save();
        app(ApprovalService::class)->recomputePlan($this->fresh());
    }

    public function reopenForApproval(): void
    {
        $nextStatus = match ($this->status) {
            PlanStatus::Draft => PlanStatus::Draft,
            PlanStatus::Superseded => PlanStatus::Superseded,
            PlanStatus::Done => PlanStatus::Done,
            default => PlanStatus::PendingApproval,
        };

        $this->forceFill([
            'revision' => ($this->revision ?? 1) + 1,
            'status' => $nextStatus->value,
        ])->save();

        if ($nextStatus !== PlanStatus::Draft && $nextStatus !== PlanStatus::Superseded && $nextStatus !== PlanStatus::Done) {
            app(ApprovalService::class)->recomputePlan($this->fresh());
        }
    }
}
