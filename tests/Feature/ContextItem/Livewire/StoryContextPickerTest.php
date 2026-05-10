<?php

use App\Enums\StoryStatus;
use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function pickerScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::Approved,
        'revision' => 1,
    ]);

    $member = User::factory()->create();
    $team->addMember($member);

    return [$story, $member];
}

test('picker lists project-scoped + story-owned items, with story-scoped pre-checked', function () {
    [$story, $member] = pickerScene();
    $project = $story->feature->project;
    $shared = ContextItem::factory()->for($project)->forText('shared')->create(['title' => 'Shared']);
    $owned = ContextItem::factory()->for($project)->for($story)->forText('owned')->create(['title' => 'Owned']);

    Livewire::actingAs($member)
        ->test('pages::context-items.story-context-picker', ['storyId' => $story->id])
        ->assertSee('Shared')
        ->assertSee('Owned')
        ->assertSee('story-scoped');
});

test('mount prefills selected with currently included project-scoped IDs only', function () {
    [$story, $member] = pickerScene();
    $project = $story->feature->project;
    $a = ContextItem::factory()->for($project)->forText()->create();
    $b = ContextItem::factory()->for($project)->forText()->create();
    $owned = ContextItem::factory()->for($project)->for($story)->forText()->create();
    $story->includedContextItems()->attach([$a->id, $owned->id]);

    Livewire::actingAs($member)
        ->test('pages::context-items.story-context-picker', ['storyId' => $story->id])
        ->assertSet('selected', [$a->id]);
});

test('save bulkSets project-scoped selection and reopens approval once', function () {
    [$story, $member] = pickerScene();
    $project = $story->feature->project;
    $a = ContextItem::factory()->for($project)->forText()->create();
    $b = ContextItem::factory()->for($project)->forText()->create();
    $story->includedContextItems()->attach([$a->id]);
    $beforeRev = $story->fresh()->revision;

    Livewire::actingAs($member)
        ->test('pages::context-items.story-context-picker', ['storyId' => $story->id])
        ->set('selected', [$b->id])
        ->call('save')
        ->assertHasNoErrors();

    $included = $story->includedContextItems()->pluck('context_items.id')->sort()->values()->all();
    expect($included)->toBe([$b->id]);
    expect($story->fresh()->revision)->toBe($beforeRev + 1);
});

test('save is a no-op when desired set matches current — no revision bump', function () {
    [$story, $member] = pickerScene();
    $project = $story->feature->project;
    $a = ContextItem::factory()->for($project)->forText()->create();
    $story->includedContextItems()->attach([$a->id]);
    $beforeRev = $story->fresh()->revision;

    Livewire::actingAs($member)
        ->test('pages::context-items.story-context-picker', ['storyId' => $story->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($story->fresh()->revision)->toBe($beforeRev);
});

test('save filters out story-scoped IDs that the client tampered with', function () {
    [$story, $member] = pickerScene();
    $project = $story->feature->project;
    $owned = ContextItem::factory()->for($project)->for($story)->forText()->create();
    // Production state: story-scoped items are auto-attached by
    // ContextItemWriter::createStoryItem. Tests using factories directly
    // need to mirror that.
    $story->includedContextItems()->attach($owned->id);
    $a = ContextItem::factory()->for($project)->forText()->create();

    Livewire::actingAs($member)
        ->test('pages::context-items.story-context-picker', ['storyId' => $story->id])
        ->set('selected', [$owned->id, $a->id])
        ->call('save')
        ->assertHasNoErrors();

    // Story-scoped item stays attached (untouched by bulkSet); project-scoped
    // item attached fresh. Tampered-in story-scoped IDs are silently dropped
    // by the picker before reaching the selector.
    $included = $story->includedContextItems()->pluck('context_items.id')->sort()->values()->all();
    expect($included)->toBe(collect([$owned->id, $a->id])->sort()->values()->all());
});

test('non-member cannot mount the picker', function () {
    [$story] = pickerScene();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test('pages::context-items.story-context-picker', ['storyId' => $story->id])
        ->assertStatus(403);
});
