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

#[Fillable(['project_id', 'name', 'slug', 'description', 'notes', 'status'])]
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }
}
