<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\HasSlug;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Fillable(['team_id', 'created_by_id', 'name', 'slug', 'description', 'status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasSlug;

    protected function slugScopeColumn(): string
    {
        return 'team_id';
    }

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    public function stories(): HasManyThrough
    {
        return $this->hasManyThrough(Story::class, Feature::class);
    }

    public function defaultApprovalPolicy(): ?ApprovalPolicy
    {
        return ApprovalPolicy::query()
            ->where('scope_type', ApprovalPolicy::SCOPE_PROJECT)
            ->where('scope_id', $this->getKey())
            ->first();
    }

    public function repos(): BelongsToMany
    {
        return $this->belongsToMany(Repo::class)
            ->withPivot('role', 'is_primary')
            ->withTimestamps();
    }

    public function primaryRepo(): ?Repo
    {
        return $this->repos()->wherePivot('is_primary', true)->first();
    }

    public function attachRepo(Repo $repo, ?string $role = null, bool $primary = false): void
    {
        $workspaceId = $this->team?->workspace_id;
        if ($workspaceId === null || (int) $repo->workspace_id !== (int) $workspaceId) {
            throw new InvalidArgumentException('Repo must belong to the same workspace as the project.');
        }

        DB::transaction(function () use ($repo, $role, $primary) {
            $existingPrimary = $this->repos()->wherePivot('is_primary', true)->exists();
            $shouldBePrimary = $primary || ! $existingPrimary;

            if ($shouldBePrimary && $existingPrimary) {
                $this->repos()->newPivotStatement()
                    ->where('project_id', $this->getKey())
                    ->update(['is_primary' => false]);
            }

            $this->repos()->syncWithoutDetaching([
                $repo->getKey() => [
                    'role' => $role,
                    'is_primary' => $shouldBePrimary,
                ],
            ]);
        });
    }

    public function setPrimaryRepo(Repo $repo): void
    {
        if (! $this->repos()->whereKey($repo->getKey())->exists()) {
            throw new InvalidArgumentException('Repo is not attached to this project.');
        }

        DB::transaction(function () use ($repo) {
            $this->repos()->newPivotStatement()
                ->where('project_id', $this->getKey())
                ->update(['is_primary' => false]);

            $this->repos()->updateExistingPivot($repo->getKey(), ['is_primary' => true]);
        });
    }

    protected function workspace(): Attribute
    {
        return Attribute::get(fn () => $this->team?->workspace);
    }
}
