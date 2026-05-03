<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\AcceptanceCriterionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Atomic observable rule a Story must satisfy.
 */
#[Fillable(['story_id', 'position', 'statement', 'criterion'])]
class AcceptanceCriterion extends Model
{
    /** @use HasFactory<AcceptanceCriterionFactory> */
    use HasFactory;

    protected $table = 'acceptance_criteria';

    protected static function booted(): void
    {
        $reopen = function (self $criterion): void {
            $story = $criterion->story;
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

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected function criterion(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->statement,
            set: fn (?string $value) => ['statement' => $value],
        );
    }

    protected function met(): Attribute
    {
        return Attribute::get(fn () => $this->tasks()->where('status', TaskStatus::Done->value)->exists());
    }
}
