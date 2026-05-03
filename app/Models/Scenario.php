<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['story_id', 'acceptance_criterion_id', 'position', 'name', 'given_text', 'when_text', 'then_text', 'notes'])]
class Scenario extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        $reopen = function (self $scenario): void {
            $story = $scenario->story;
            if (! $story) {
                return;
            }

            $story->silentlyForceFill([
                'status' => $story->status === \App\Enums\StoryStatus::Draft
                    ? \App\Enums\StoryStatus::Draft->value
                    : \App\Enums\StoryStatus::PendingApproval->value,
                'revision' => ($story->revision ?? 1) + 1,
            ]);

            if ($story->currentPlan) {
                $story->currentPlan->reopenForApproval();
            }

            app(\App\Services\ApprovalService::class)->recompute($story->fresh());
        };

        static::created($reopen);
        static::updated($reopen);
        static::deleted($reopen);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function acceptanceCriterion(): BelongsTo
    {
        return $this->belongsTo(AcceptanceCriterion::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }
}
