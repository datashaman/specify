<?php

use App\Enums\StoryStatus;
use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\Context\ContextItemSelector;

function selectorScene(): array
{
    $project = Project::factory()->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::Approved,
        'revision' => 1,
    ]);
    $actor = User::factory()->create();

    return compact('project', 'feature', 'story', 'actor');
}

test('setIncluded toggles attachment and reopens approval once', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = selectorScene();
    $item = ContextItem::factory()->for($project)->forText()->create();
    $beforeRev = $story->fresh()->revision;

    app(ContextItemSelector::class)->setIncluded($story, $item, true, $actor);

    expect($story->includedContextItems()->whereKey($item->id)->exists())->toBeTrue();
    expect($story->fresh()->revision)->toBe($beforeRev + 1);

    // Calling again with same state must be a no-op (no second reopen).
    app(ContextItemSelector::class)->setIncluded($story, $item, true, $actor);
    expect($story->fresh()->revision)->toBe($beforeRev + 1);

    app(ContextItemSelector::class)->setIncluded($story, $item, false, $actor);
    expect($story->includedContextItems()->whereKey($item->id)->exists())->toBeFalse();
    expect($story->fresh()->revision)->toBe($beforeRev + 2);
});

test('setIncluded refuses to toggle a story-scoped item', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = selectorScene();
    $item = ContextItem::factory()->for($project)->for($story)->forText()->create();

    expect(fn () => app(ContextItemSelector::class)->setIncluded($story, $item, false, $actor))
        ->toThrow(InvalidArgumentException::class);
});

test('setIncluded refuses cross-project items', function () {
    ['story' => $story, 'actor' => $actor] = selectorScene();
    $otherProject = Project::factory()->create();
    $item = ContextItem::factory()->for($otherProject)->forText()->create();

    expect(fn () => app(ContextItemSelector::class)->setIncluded($story, $item, true, $actor))
        ->toThrow(InvalidArgumentException::class);
});

test('bulkSet replaces project-scoped selection in one reopen', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = selectorScene();
    $a = ContextItem::factory()->for($project)->forText()->create();
    $b = ContextItem::factory()->for($project)->forText()->create();
    $c = ContextItem::factory()->for($project)->forText()->create();
    $story->includedContextItems()->attach([$a->id, $b->id]);
    $beforeRev = $story->fresh()->revision;

    app(ContextItemSelector::class)->bulkSet($story, [$b->id, $c->id], $actor);

    $included = $story->includedContextItems()->pluck('context_items.id')->sort()->values()->all();
    expect($included)->toBe(collect([$b->id, $c->id])->sort()->values()->all());
    expect($story->fresh()->revision)->toBe($beforeRev + 1);
});

test('bulkSet is a no-op when desired set matches current', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = selectorScene();
    $a = ContextItem::factory()->for($project)->forText()->create();
    $story->includedContextItems()->attach([$a->id]);
    $beforeRev = $story->fresh()->revision;

    app(ContextItemSelector::class)->bulkSet($story, [$a->id], $actor);

    expect($story->fresh()->revision)->toBe($beforeRev);
});

test('bulkSet preserves story-scoped attachments and only manages project-scoped IDs', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = selectorScene();
    $owned = ContextItem::factory()->for($project)->for($story)->forText()->create();
    $story->includedContextItems()->attach([$owned->id]);

    $extra = ContextItem::factory()->for($project)->forText()->create();

    app(ContextItemSelector::class)->bulkSet($story, [$extra->id], $actor);

    $included = $story->includedContextItems()->pluck('context_items.id')->sort()->values()->all();
    expect($included)->toBe(collect([$owned->id, $extra->id])->sort()->values()->all());
});

test('bulkSet rejects cross-project items', function () {
    ['story' => $story, 'actor' => $actor] = selectorScene();
    $otherProject = Project::factory()->create();
    $foreign = ContextItem::factory()->for($otherProject)->forText()->create();

    expect(fn () => app(ContextItemSelector::class)->bulkSet($story, [$foreign->id], $actor))
        ->toThrow(InvalidArgumentException::class);
});

test('bulkSet rejects story-scoped items belonging to a different story', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = selectorScene();
    $otherStory = Story::factory()->for($story->feature)->create();
    $alien = ContextItem::factory()->for($project)->for($otherStory)->forText()->create();

    expect(fn () => app(ContextItemSelector::class)->bulkSet($story, [$alien->id], $actor))
        ->toThrow(InvalidArgumentException::class);
});
