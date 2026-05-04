<?php

namespace App\Services\Plans;

use App\Models\Story;
use Closure;
use Illuminate\Support\Facades\DB;

class PlanVersionAllocator
{
    /**
     * @template TReturn
     *
     * @param  Closure(int, Story): TReturn  $callback
     * @return TReturn
     */
    public function withNextVersion(Story $story, Closure $callback): mixed
    {
        return DB::transaction(function () use ($story, $callback): mixed {
            $lockedStory = Story::query()
                ->whereKey($story->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $nextVersion = ((int) $lockedStory->plans()->max('version')) + 1;

            return $callback($nextVersion, $lockedStory);
        });
    }
}
