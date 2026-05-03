<?php

use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
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

test('author can attach project context items to a story idempotently', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['created_by_id' => $user->id]);
    $first = ContextItem::factory()->for($project)->create(['title' => 'Architecture notes']);
    $second = ContextItem::factory()->for($project)->create(['title' => 'Customer interview']);

    $story->contextItems()->attach($first);

    $this->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->postJson(route('stories.context-items.store', [$project, $story]), [
            '_token' => 'test-token',
            'context_item_ids' => [$first->id, $second->id, $second->id],
        ])
        ->assertSuccessful()
        ->assertJsonPath('story_id', $story->id)
        ->assertJsonPath('context_items.0.id', $first->id)
        ->assertJsonPath('context_items.1.id', $second->id)
        ->assertJsonCount(2, 'context_items');

    expect($story->fresh()->contextItems()->orderBy('context_items.id')->pluck('context_items.id')->all())
        ->toBe([$first->id, $second->id]);
});

test('attached context items must all belong to the story project', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['created_by_id' => $user->id]);
    $existingContextItem = ContextItem::factory()->for($project)->create();
    $sameProjectContextItem = ContextItem::factory()->for($project)->create();
    $otherContextItem = ContextItem::factory()->create();

    $story->contextItems()->attach($existingContextItem);

    $this->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->postJson(route('stories.context-items.store', [$project, $story]), [
            '_token' => 'test-token',
            'context_item_ids' => [$sameProjectContextItem->id, $otherContextItem->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('context_item_ids')
        ->assertJsonPath('errors.context_item_ids.0', 'Only context items from this story\'s project can be attached.');

    expect($story->fresh()->contextItems()->pluck('context_items.id')->all())
        ->toBe([$existingContextItem->id]);
});

test('author can detach a context item from a story', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['created_by_id' => $user->id]);
    $contextItem = ContextItem::factory()->for($project)->create();

    $story->contextItems()->attach($contextItem);

    $this->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->deleteJson(route('stories.context-items.destroy', [$project, $story, $contextItem]), [
            '_token' => 'test-token',
        ])
        ->assertSuccessful()
        ->assertJsonPath('story_id', $story->id)
        ->assertJsonPath('context_item_id', $contextItem->id)
        ->assertJsonPath('detached', true);

    expect($story->fresh()->contextItems)->toHaveCount(0);
});

test('detaching a project context item is idempotent when it is not attached', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['created_by_id' => $user->id]);
    $contextItem = ContextItem::factory()->for($project)->create();

    $this->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->deleteJson(route('stories.context-items.destroy', [$project, $story, $contextItem]), [
            '_token' => 'test-token',
        ])
        ->assertSuccessful()
        ->assertJsonPath('story_id', $story->id)
        ->assertJsonPath('context_item_id', $contextItem->id)
        ->assertJsonPath('detached', true);

    expect($story->fresh()->contextItems)->toHaveCount(0);
});

test('detached context item must belong to the story project', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['created_by_id' => $user->id]);
    $otherContextItem = ContextItem::factory()->create();

    $this->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->deleteJson(route('stories.context-items.destroy', [$project, $story, $otherContextItem]), [
            '_token' => 'test-token',
        ])
        ->assertNotFound();
});

test('story must belong to the nested project when attaching context items', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $otherProject = Project::factory()->for($team)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $story = Story::factory()->for($otherFeature)->create(['created_by_id' => $user->id]);
    $contextItem = ContextItem::factory()->for($project)->create();

    $this->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->postJson(route('stories.context-items.store', [$project, $story]), [
            '_token' => 'test-token',
            'context_item_ids' => [$contextItem->id],
        ])
        ->assertNotFound();
});

test('non author member cannot attach context items to a story', function () {
    $author = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($author);
    $team->addMember($member);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['created_by_id' => $author->id]);
    $contextItem = ContextItem::factory()->for($project)->create();

    $this->actingAs($member)
        ->withSession(['_token' => 'test-token'])
        ->postJson(route('stories.context-items.store', [$project, $story]), [
            '_token' => 'test-token',
            'context_item_ids' => [$contextItem->id],
        ])
        ->assertForbidden();
});
