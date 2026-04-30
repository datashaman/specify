<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Implementing models must define:
 *   - protected function slugScopeColumn(): string  (e.g. 'project_id')
 *   - public string|int|null $name attribute (sluggable source)
 *
 * The slug is stable across renames: it's only generated when the model is
 * created with a null/empty slug, never re-derived on update. To rename the
 * slug deliberately, write the new value explicitly.
 */
trait HasSlug
{
    protected static function bootHasSlug(): void
    {
        static::creating(function ($model) {
            if (! empty($model->slug)) {
                return;
            }
            $model->slug = static::generateSlug(
                $model->name ?? '',
                $model->slugScopeColumn(),
                $model->{$model->slugScopeColumn()},
            );
        });
    }

    public static function generateSlug(string $source, string $scopeColumn, mixed $scopeValue, ?int $excludeId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $i = 2;
        while (static::slugExists($slug, $scopeColumn, $scopeValue, $excludeId)) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    protected static function slugExists(string $slug, string $scopeColumn, mixed $scopeValue, ?int $excludeId): bool
    {
        $query = static::query()
            ->where($scopeColumn, $scopeValue)
            ->where('slug', $slug);
        if ($excludeId !== null) {
            $query->where((new static)->getKeyName(), '!=', $excludeId);
        }

        return $query->exists();
    }

    public function scopeWhereSlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }
}
