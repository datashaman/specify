<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\AcceptanceCriterionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['story_id', 'position', 'criterion'])]
class AcceptanceCriterion extends Model
{
    /** @use HasFactory<AcceptanceCriterionFactory> */
    use HasFactory;

    protected $table = 'acceptance_criteria';

    protected static function booted(): void
    {
        $bump = function (self $ac) {
            if ($story = $ac->story) {
                $story->forceFill(['revision' => ($story->revision ?? 1) + 1])->save();
            }
        };

        static::saved($bump);
        static::deleted($bump);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function task(): HasOne
    {
        return $this->hasOne(Task::class);
    }

    /**
     * "Met" is derived from the linked task's status — Done = met.
     */
    protected function met(): Attribute
    {
        return Attribute::get(fn () => $this->task?->status === TaskStatus::Done);
    }
}
