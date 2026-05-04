<?php

namespace App\Services\Stories;

use App\Models\Scenario;
use App\Models\Story;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ScenarioWriter
{
    public function __construct(private StoryRevisionLifecycle $revisions) {}

    /**
     * @param  array{
     *     acceptance_criterion_id?: int|null,
     *     position?: int|null,
     *     name: string,
     *     given_text?: string|null,
     *     when_text?: string|null,
     *     then_text?: string|null,
     *     notes?: string|null
     * }  $attributes
     */
    public function create(Story $story, array $attributes): Scenario
    {
        return DB::transaction(function () use ($story, $attributes): Scenario {
            $story = Story::query()->whereKey($story->getKey())->lockForUpdate()->firstOrFail();
            $criterionId = $attributes['acceptance_criterion_id'] ?? null;
            $this->ensureCriterionBelongsToStory($story, $criterionId);

            $scenario = Scenario::withoutEvents(function () use ($story, $attributes, $criterionId): Scenario {
                return $story->scenarios()->create([
                    'acceptance_criterion_id' => $criterionId,
                    'position' => $attributes['position'] ?? ((int) $story->scenarios()->max('position') + 1),
                    'name' => $attributes['name'],
                    'given_text' => $attributes['given_text'] ?? null,
                    'when_text' => $attributes['when_text'] ?? null,
                    'then_text' => $attributes['then_text'] ?? null,
                    'notes' => $attributes['notes'] ?? null,
                ]);
            });

            $this->revisions->recordContentArtifactChanged($story);

            return $scenario;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Scenario $scenario, array $changes): void
    {
        DB::transaction(function () use ($scenario, $changes): void {
            $story = $scenario->story()->firstOrFail();

            if (array_key_exists('acceptance_criterion_id', $changes)) {
                $this->ensureCriterionBelongsToStory($story, $changes['acceptance_criterion_id']);
            }

            $changed = Scenario::withoutEvents(function () use ($scenario, $changes): bool {
                $scenario->forceFill($changes);

                if (! $scenario->isDirty()) {
                    return false;
                }

                $scenario->save();

                return true;
            });

            if (! $changed) {
                return;
            }

            $this->revisions->recordContentArtifactChanged($story);
        });
    }

    private function ensureCriterionBelongsToStory(Story $story, mixed $criterionId): void
    {
        if ($criterionId === null) {
            return;
        }

        if (! $story->acceptanceCriteria()->whereKey($criterionId)->exists()) {
            throw new InvalidArgumentException("acceptance_criterion_id {$criterionId} does not belong to this story.");
        }
    }
}
