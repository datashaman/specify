<?php

namespace App\Services\Stories;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\ApprovalService;

class StoryRevisionLifecycle
{
    /**
     * @var list<string>
     */
    private const WATCHED_ATTRIBUTES = ['name', 'kind', 'actor', 'intent', 'outcome', 'description', 'notes'];

    public function __construct(private ApprovalService $approvals) {}

    public function assignInitialPosition(Story $story): void
    {
        if (! empty($story->position)) {
            return;
        }

        $max = Story::query()
            ->where('feature_id', $story->feature_id)
            ->max('position') ?? 0;

        $story->position = $max + 1;
    }

    public function bumpRevisionForWatchedChanges(Story $story): void
    {
        if ($story->isDirty('revision')) {
            return;
        }

        if (! collect(self::WATCHED_ATTRIBUTES)->contains(fn (string $key) => $story->isDirty($key))) {
            return;
        }

        $story->revision = ($story->revision ?? 1) + 1;
    }

    /**
     * @param  callable(callable(): void): void  $withoutRevisionBump
     */
    public function handleRevisionChanged(Story $story, callable $withoutRevisionBump): void
    {
        if (! $story->wasChanged('revision')) {
            return;
        }

        $story->currentPlan()->first()?->reopenForApproval();

        if (in_array($story->status, [StoryStatus::Draft, StoryStatus::Rejected, StoryStatus::Done, StoryStatus::Cancelled], true)) {
            return;
        }

        $withoutRevisionBump(fn () => $this->approvals->recompute($story));
    }
}
