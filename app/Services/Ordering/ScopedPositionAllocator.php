<?php

namespace App\Services\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ScopedPositionAllocator
{
    /**
     * @template TOwner of Model
     * @template TReturn
     *
     * @param  TOwner  $owner
     * @param  Closure(int, TOwner): TReturn  $callback
     * @return TReturn
     */
    public function withNextPosition(Model $owner, string $relation, Closure $callback): mixed
    {
        return DB::transaction(function () use ($owner, $relation, $callback): mixed {
            /** @var TOwner $lockedOwner */
            $lockedOwner = $owner->newQuery()
                ->whereKey($owner->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $nextPosition = ((int) $lockedOwner->{$relation}()->max('position')) + 1;

            return $callback($nextPosition, $lockedOwner);
        });
    }
}
