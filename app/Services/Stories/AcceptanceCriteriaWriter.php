<?php

namespace App\Services\Stories;

use App\Models\AcceptanceCriterion;
use App\Models\Story;
use App\Services\Ordering\ScopedPositionAllocator;
use Illuminate\Support\Facades\DB;

class AcceptanceCriteriaWriter
{
    public function __construct(
        private StoryRevisionLifecycle $revisions,
        private ScopedPositionAllocator $positions,
    ) {}

    public function add(Story $story, string $statement, ?int $position = null): AcceptanceCriterion
    {
        return $this->positions->withNextPosition($story, 'acceptanceCriteria', function (int $nextPosition, Story $story) use ($statement, $position): AcceptanceCriterion {
            $criterion = AcceptanceCriterion::withoutEvents(function () use ($story, $statement, $position, $nextPosition): AcceptanceCriterion {
                return $story->acceptanceCriteria()->create([
                    'statement' => $statement,
                    'position' => $position ?? $nextPosition,
                ]);
            });

            $this->revisions->recordContentArtifactChanged($story);

            return $criterion;
        });
    }

    /**
     * @param  list<string>  $statements
     */
    public function replace(Story $story, array $statements): void
    {
        DB::transaction(function () use ($story, $statements): void {
            AcceptanceCriterion::withoutEvents(function () use ($story, $statements): void {
                $story->acceptanceCriteria()->delete();

                foreach ($statements as $i => $statement) {
                    $story->acceptanceCriteria()->create([
                        'statement' => $statement,
                        'position' => $i + 1,
                    ]);
                }
            });

            $this->revisions->recordContentArtifactChanged($story);
        });
    }
}
