<?php

use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

test('story can be attached to multiple project context items', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $first = ContextItem::factory()->for($project)->create(['title' => 'Architecture notes']);
    $second = ContextItem::factory()->for($project)->create(['title' => 'Customer interview']);

    $story->contextItems()->attach([$first->id, $second->id]);

    expect($story->fresh()->contextItems()->orderBy('context_items.id')->pluck('title')->all())
        ->toBe(['Architecture notes', 'Customer interview'])
        ->and($first->fresh()->stories->pluck('id')->all())
        ->toBe([$story->id]);
});

test('story context item pivot is indexed for story lookup', function () {
    $indexes = collect(Schema::getIndexes('context_item_story'));

    expect($indexes->firstWhere('primary', true)['columns'] ?? null)
        ->toBe(['story_id', 'context_item_id'])
        ->and($indexes->contains(fn (array $index) => $index['columns'] === ['context_item_id']))
        ->toBeTrue();
});
