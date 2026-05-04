<?php

namespace App\Services\Stories;

use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\Story;
use Illuminate\Support\Facades\DB;

class StoryContractEditor
{
    public function __construct(private StoryRevisionLifecycle $revisions) {}

    /**
     * @param  list<array{id: int|null, statement: string}>  $criteria
     */
    public function update(Story $story, string $name, string $description, array $criteria): bool
    {
        abort_if(in_array($story->status, [StoryStatus::Done, StoryStatus::Cancelled, StoryStatus::Rejected], true), 422, 'Story is read-only.');

        $changed = DB::transaction(function () use ($story, $name, $description, $criteria): bool {
            $changed = false;

            $trimmedName = trim($name);
            if ($story->name !== $trimmedName || $story->description !== $description) {
                Story::withoutRevisionBump(function () use ($story, $trimmedName, $description): void {
                    $story->fill([
                        'name' => $trimmedName,
                        'description' => $description,
                    ])->save();
                });
                $changed = true;
            }

            return $this->syncCriteria($story, $criteria) || $changed;
        });

        if ($changed) {
            $this->revisions->recordContentArtifactChanged($story->fresh());
        }

        return $changed;
    }

    /**
     * @param  list<array{id: int|null, statement: string}>  $criteria
     */
    private function syncCriteria(Story $story, array $criteria): bool
    {
        $existing = $story->acceptanceCriteria()->get()->keyBy('id');
        $kept = [];
        $changed = false;

        AcceptanceCriterion::withoutEvents(function () use ($story, $criteria, $existing, &$kept, &$changed): void {
            foreach ($criteria as $i => $row) {
                $position = $i + 1;
                $text = trim((string) ($row['statement'] ?? ''));
                $id = $row['id'] ?? null;

                if ($id !== null && $existing->has($id)) {
                    $criterion = $existing[$id];
                    if ($criterion->statement !== $text || $criterion->position !== $position) {
                        $criterion->update(['statement' => $text, 'position' => $position]);
                        $changed = true;
                    }
                    $kept[] = $id;

                    continue;
                }

                $story->acceptanceCriteria()->create([
                    'position' => $position,
                    'statement' => $text,
                ]);
                $changed = true;
            }

            $toDelete = $existing->keys()->diff($kept);
            if ($toDelete->isNotEmpty()) {
                AcceptanceCriterion::whereIn('id', $toDelete)->delete();
                $changed = true;
            }
        });

        return $changed;
    }
}
