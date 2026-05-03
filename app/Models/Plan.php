<?php

namespace App\Models;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['story_id', 'version', 'name', 'summary', 'design_notes', 'implementation_notes', 'risks', 'assumptions', 'source', 'source_label', 'status'])]
class Plan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
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
}
