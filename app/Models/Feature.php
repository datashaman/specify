<?php

namespace App\Models;

use App\Enums\FeatureStatus;
use App\Models\Concerns\HasSlug;
use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Product owner's framing of a capability under a Project. Container for Stories.
 */
#[Fillable(['project_id', 'position', 'name', 'slug', 'description', 'notes', 'status'])]
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory, HasSlug;

    protected function slugScopeColumn(): string
    {
        return 'project_id';
    }

    protected function casts(): array
    {
        return [
            'status' => FeatureStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $feature): void {
            if (! empty($feature->position)) {
                return;
            }

            $feature->position = DB::transaction(function () use ($feature): int {
                $project = Project::query()
                    ->whereKey($feature->project_id)
                    ->lockForUpdate()
                    ->firstOrFail(['id', 'next_feature_position']);

                $position = (int) $project->next_feature_position;

                $project->forceFill(['next_feature_position' => $position + 1])->save();

                return $position;
            });
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }
}
