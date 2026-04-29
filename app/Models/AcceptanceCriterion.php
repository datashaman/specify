<?php

namespace App\Models;

use Database\Factories\AcceptanceCriterionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['story_id', 'position', 'criterion', 'met'])]
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

    protected function casts(): array
    {
        return [
            'met' => 'boolean',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
