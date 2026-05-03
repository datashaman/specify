<?php

namespace App\Models;

use Database\Factories\ContextItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['project_id', 'type', 'title', 'description', 'metadata'])]
class ContextItem extends Model
{
    /** @use HasFactory<ContextItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class)->withTimestamps();
    }
}
