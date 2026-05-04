<?php

namespace App\Services\Stories;

use App\Models\AcceptanceCriterion;
use App\Models\Story;
use Illuminate\Support\Facades\DB;

class AcceptanceCriteriaWriter
{
    public function __construct(private StoryRevisionLifecycle $revisions) {}

    public function add(Story $story, string $statement, ?int $position = null): AcceptanceCriterion
    {
        return DB::transaction(function () use ($story, $statement, $position): AcceptanceCriterion {
            $criterion = AcceptanceCriterion::withoutEvents(function () use ($story, $statement, $position): AcceptanceCriterion {
                return $story->acceptanceCriteria()->create([
                    'statement' => $statement,
                    'position' => $position ?? ((int) ($story->acceptanceCriteria()->max('position') ?? 0) + 1),
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
