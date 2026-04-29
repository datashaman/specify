<?php

namespace App\Models;

use App\Enums\RepoProvider;
use Database\Factories\RepoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['workspace_id', 'name', 'provider', 'url', 'default_branch', 'access_token', 'webhook_secret', 'metadata'])]
class Repo extends Model
{
    /** @use HasFactory<RepoFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => RepoProvider::class,
            'access_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('role', 'is_primary')
            ->withTimestamps();
    }
}
