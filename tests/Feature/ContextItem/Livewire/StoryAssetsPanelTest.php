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

function storyPanelScene(): array
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

test('panel renders only story-owned items', function () {
    [$story, $member] = storyPanelScene();
    $project = $story->feature->project;

    $owned = ContextItem::factory()->for($project)->for($story)->forText('owned')->create(['title' => 'Owned']);
    $shared = ContextItem::factory()->for($project)->forText('shared')->create(['title' => 'Shared']);

    Livewire::actingAs($member)
        ->test('pages::context-items.story-assets-panel', ['storyId' => $story->id])
        ->assertSee('Owned')
        ->assertDontSee('Shared');
});

test('non-member cannot mount the story panel', function () {
    [$story] = storyPanelScene();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test('pages::context-items.story-assets-panel', ['storyId' => $story->id])
        ->assertStatus(403);
});

test('create story-scoped text item bumps revision (reopens approval)', function () {
    [$story, $member] = storyPanelScene();
    $beforeRev = $story->fresh()->revision;

    Livewire::actingAs($member)
        ->test('pages::context-items.story-assets-panel', ['storyId' => $story->id])
        ->set('newType', 'text')
        ->set('newTitle', 'Story note')
        ->set('newBody', 'Hot edit')
        ->call('create')
        ->assertHasNoErrors();

    expect($story->fresh()->revision)->toBe($beforeRev + 1);
    expect($story->ownedContextItems()->count())->toBe(1);
});

test('edit story-scoped item bumps revision', function () {
    [$story, $member] = storyPanelScene();
    $project = $story->feature->project;
    $item = ContextItem::factory()->for($project)->for($story)->forText('old')->create(['title' => 'Old']);
    $beforeRev = $story->fresh()->revision;

    Livewire::actingAs($member)
        ->test('pages::context-items.story-assets-panel', ['storyId' => $story->id])
        ->call('startEdit', $item->id)
        ->set('editTitle', 'New')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($story->fresh()->revision)->toBe($beforeRev + 1);
    expect($item->fresh()->title)->toBe('New');
});

test('delete story-scoped item bumps revision and removes the row', function () {
    [$story, $member] = storyPanelScene();
    $project = $story->feature->project;
    $item = ContextItem::factory()->for($project)->for($story)->forText('x')->create();
    $beforeRev = $story->fresh()->revision;

    Livewire::actingAs($member)
        ->test('pages::context-items.story-assets-panel', ['storyId' => $story->id])
        ->call('delete', $item->id)
        ->assertHasNoErrors();

    expect($story->fresh()->revision)->toBe($beforeRev + 1);
    expect(ContextItem::query()->whereKey($item->id)->exists())->toBeFalse();
});
