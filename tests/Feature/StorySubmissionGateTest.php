<?php

use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('submitForApproval refuses a story with no acceptance criteria', function () {
    $story = Story::factory()->create(['status' => StoryStatus::Draft]);

    expect($story->acceptanceCriteria()->count())->toBe(0);

    expect(fn () => $story->submitForApproval())
        ->toThrow(RuntimeException::class, 'Add at least one acceptance criterion before submitting.');

    expect($story->fresh()->status)->toBe(StoryStatus::Draft);
});

test('submitForApproval succeeds when at least one acceptance criterion exists', function () {
    $story = Story::factory()->create(['status' => StoryStatus::Draft]);
    AcceptanceCriterion::factory()->for($story)->create();

    $story->submitForApproval();

    // Status moves out of Draft. The exact landing status depends on the
    // policy (PendingApproval if approvals are required; Approved if auto).
    expect($story->fresh()->status)->not->toBe(StoryStatus::Draft);
});
