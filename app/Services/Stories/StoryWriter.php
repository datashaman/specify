<?php

namespace App\Services\Stories;

use App\Enums\StoryKind;
use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\Feature;
use App\Models\Story;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StoryWriter
{
    public function __construct(
        private readonly AcceptanceCriteriaWriter $criteria,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     kind?: StoryKind,
     *     actor?: string|null,
     *     intent?: string|null,
     *     outcome?: string|null,
     *     description?: string|null,
     *     notes?: string|null,
     *     status?: StoryStatus,
     *     acceptance_criteria?: list<string>
     * }  $attributes
     */
    public function create(Feature $feature, User $creator, array $attributes): Story
    {
        return DB::transaction(function () use ($feature, $creator, $attributes): Story {
            $story = $feature->stories()->create([
                'created_by_id' => $creator->getKey(),
                'name' => $attributes['name'],
                'kind' => $attributes['kind'] ?? StoryKind::UserStory,
                'actor' => $attributes['actor'] ?? null,
                'intent' => $attributes['intent'] ?? null,
                'outcome' => $attributes['outcome'] ?? null,
                'description' => $attributes['description'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => $attributes['status'] ?? StoryStatus::Draft,
                'revision' => 1,
            ]);

            $this->createInitialAcceptanceCriteria($story, $attributes['acceptance_criteria'] ?? []);

            return $story;
        });
    }

    /**
     * @param  array{
     *     name?: string,
     *     kind?: StoryKind,
     *     actor?: string|null,
     *     intent?: string|null,
     *     outcome?: string|null,
     *     description?: string|null,
     *     notes?: string|null,
     *     status?: StoryStatus
     * }  $changes
     * @param  list<string>|null  $acceptanceCriteria
     */
    public function update(Story $story, array $changes = [], ?array $acceptanceCriteria = null): void
    {
        DB::transaction(function () use ($story, $changes, $acceptanceCriteria): void {
            if ($changes !== []) {
                if ($acceptanceCriteria !== null) {
                    Story::withoutRevisionBump(function () use ($story, $changes): void {
                        $story->fill($changes)->save();
                    });
                } else {
                    $story->fill($changes)->save();
                }
            }

            if ($acceptanceCriteria !== null) {
                $this->criteria->replace($story, $acceptanceCriteria);
            }
        });
    }

    /**
     * @param  array<int|string, string>  $criteria
     */
    private function createInitialAcceptanceCriteria(Story $story, array $criteria): void
    {
        AcceptanceCriterion::withoutEvents(function () use ($story, $criteria): void {
            foreach (array_values($criteria) as $i => $criterion) {
                $story->acceptanceCriteria()->create([
                    'statement' => $criterion,
                    'position' => $i + 1,
                ]);
            }
        });
    }
}
