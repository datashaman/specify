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

            AcceptanceCriterion::withoutEvents(function () use ($story, $attributes): void {
                foreach (($attributes['acceptance_criteria'] ?? []) as $i => $criterion) {
                    $story->acceptanceCriteria()->create([
                        'statement' => $criterion,
                        'position' => $i + 1,
                    ]);
                }
            });

            return $story;
        });
    }
}
