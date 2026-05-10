<?php

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;

test('isProjectScoped vs isStoryScoped reflect story_id', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();

    $projectItem = ContextItem::factory()->for($project)->forText('hello')->create();
    $storyItem = ContextItem::factory()->for($project)->for($story)->forText('world')->create();

    expect($projectItem->isProjectScoped())->toBeTrue();
    expect($projectItem->isStoryScoped())->toBeFalse();
    expect($storyItem->isStoryScoped())->toBeTrue();
    expect($storyItem->isProjectScoped())->toBeFalse();
});

test('bodyForContext returns summary when ready', function () {
    $item = ContextItem::factory()->forText('the long body')->create([
        'summary' => 'short summary',
        'summary_status' => ContextItemSummaryStatus::Ready,
    ]);

    expect($item->bodyForContext())->toBe('short summary');
});

test('bodyForContext falls back to truncated raw body when no summary', function () {
    $body = str_repeat('a', ContextItem::BODY_FALLBACK_CHARS + 200);
    $item = ContextItem::factory()->forText($body)->create([
        'summary_status' => ContextItemSummaryStatus::Skipped,
    ]);

    $rendered = $item->bodyForContext();

    expect(mb_strlen($rendered))->toBeLessThanOrEqual(ContextItem::BODY_FALLBACK_CHARS + 1);
    expect($rendered)->toEndWith('…');
});

test('bodyForContext for link returns the url', function () {
    $item = ContextItem::factory()->forLink('https://example.test/spec')->create();

    expect($item->bodyForContext())->toBe('https://example.test/spec');
});

test('Project hasMany contextItems, Story owned and includedContextItems work', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();

    $owned = ContextItem::factory()->for($project)->for($story)->forText()->create();
    $other = ContextItem::factory()->for($project)->forText()->create();
    $story->includedContextItems()->attach($other);

    expect($project->contextItems()->count())->toBe(2);
    expect($story->ownedContextItems()->pluck('id')->all())->toBe([$owned->id]);
    expect($story->includedContextItems()->pluck('context_items.id')->sort()->values()->all())
        ->toBe([$other->id]);
});

test('availableContextItems returns project-scoped + own story items', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $otherStory = Story::factory()->for($feature)->create();

    $projectItem = ContextItem::factory()->for($project)->forText()->create();
    $ownItem = ContextItem::factory()->for($project)->for($story)->forText()->create();
    $otherStoryItem = ContextItem::factory()->for($project)->for($otherStory)->forText()->create();

    $ids = $story->availableContextItems()->pluck('id')->sort()->values()->all();
    $expected = collect([$projectItem->id, $ownItem->id])->sort()->values()->all();

    expect($ids)->toBe($expected);
    expect($ids)->not->toContain($otherStoryItem->id);
});

test('cast for type and summary_status returns enums', function () {
    $item = ContextItem::factory()->forLink()->create([
        'summary_status' => ContextItemSummaryStatus::Skipped,
    ]);

    expect($item->type)->toBe(ContextItemType::Link);
    expect($item->summary_status)->toBe(ContextItemSummaryStatus::Skipped);
});
