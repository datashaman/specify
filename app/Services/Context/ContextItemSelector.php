<?php

namespace App\Services\Context;

use App\Models\ContextItem;
use App\Models\Story;
use App\Models\User;
use App\Services\Stories\StoryRevisionLifecycle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Toggles project-scoped ContextItems into / out of a Story's selection.
 *
 * Approval reopen invariant: at most one `recordContentArtifactChanged()`
 * call per public method, fired only when the included set actually
 * changed. Story-scoped items are auto-included by `ContextItemWriter`
 * and cannot be toggled here.
 */
class ContextItemSelector
{
    public function __construct(private StoryRevisionLifecycle $revisions) {}

    public function setIncluded(Story $story, ContextItem $item, bool $included, User $actor): void
    {
        $this->ensureSameProject($story, $item);

        if ($item->isStoryScoped()) {
            throw new InvalidArgumentException('Story-scoped items are auto-included and cannot be toggled.');
        }

        DB::transaction(function () use ($story, $item, $included, $actor): void {
            $isAttached = $story->includedContextItems()->whereKey($item->getKey())->exists();

            if ($included === $isAttached) {
                return;
            }

            if ($included) {
                $story->includedContextItems()->attach($item->getKey(), [
                    'included_at' => now(),
                    'included_by_id' => $actor->getKey(),
                ]);
            } else {
                $story->includedContextItems()->detach($item->getKey());
            }

            $this->revisions->recordContentArtifactChanged($story);
        });
    }

    /**
     * Replace the project-scoped selection for a Story with the given set
     * of ContextItem IDs. Story-scoped items remain attached either way —
     * they're managed by `ContextItemWriter::createStoryItem` / `delete`,
     * never by the picker.
     *
     * @param  array<int|string>  $itemIds  Desired included **project-scoped** item IDs.
     *                                      Story-scoped IDs are rejected; they cannot be
     *                                      detached this way and would conflict with the
     *                                      pivot's composite primary key on re-attach.
     */
    public function bulkSet(Story $story, array $itemIds, User $actor): void
    {
        DB::transaction(function () use ($story, $itemIds, $actor): void {
            $desired = array_values(array_unique(array_map('intval', $itemIds)));

            if ($desired !== []) {
                $items = ContextItem::query()->whereIn('id', $desired)->get();
                if ($items->count() !== count($desired)) {
                    throw new InvalidArgumentException('One or more context items do not exist.');
                }

                foreach ($items as $item) {
                    $this->ensureSameProject($story, $item);
                    if ($item->isStoryScoped()) {
                        throw new InvalidArgumentException(
                            "Context item {$item->getKey()} is story-scoped; bulkSet only manages project-scoped selections."
                        );
                    }
                }
            }

            $currentProjectScoped = $story->includedContextItems()
                ->whereNull('context_items.story_id')
                ->pluck('context_items.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            sort($currentProjectScoped);
            $sortedDesired = $desired;
            sort($sortedDesired);

            if ($currentProjectScoped === $sortedDesired) {
                return;
            }

            $toAttach = array_diff($desired, $currentProjectScoped);
            $toDetach = array_diff($currentProjectScoped, $desired);

            if ($toDetach !== []) {
                $story->includedContextItems()->detach($toDetach);
            }

            if ($toAttach !== []) {
                $now = now();
                $payload = [];
                foreach ($toAttach as $id) {
                    $payload[$id] = [
                        'included_at' => $now,
                        'included_by_id' => $actor->getKey(),
                    ];
                }
                $story->includedContextItems()->attach($payload);
            }

            $this->revisions->recordContentArtifactChanged($story);
        });
    }

    private function ensureSameProject(Story $story, ContextItem $item): void
    {
        $storyProjectId = $story->feature?->project_id;
        if ($storyProjectId === null) {
            throw new InvalidArgumentException('Story is not attached to a Feature with a Project.');
        }

        if ((int) $item->project_id !== (int) $storyProjectId) {
            throw new InvalidArgumentException(
                "Context item {$item->getKey()} belongs to a different Project than story {$story->getKey()}."
            );
        }
    }
}
