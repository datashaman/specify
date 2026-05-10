<?php

use App\Enums\ContextItemType;
use App\Enums\StoryStatus;
use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
    // Auto-included into the Story's selection by ContextItemWriter::createStoryItem.
    expect($story->includedContextItems()->count())->toBe(1);
});

test('create story-scoped link item auto-includes and bumps revision once', function () {
    [$story, $member] = storyPanelScene();
    $beforeRev = $story->fresh()->revision;

    Livewire::actingAs($member)
        ->test('pages::context-items.story-assets-panel', ['storyId' => $story->id])
        ->set('newType', 'link')
        ->set('newTitle', 'Figma')
        ->set('newUrl', 'https://figma.com/x')
        ->call('create')
        ->assertHasNoErrors();

    $item = $story->ownedContextItems()->first();
    expect($item->type)->toBe(ContextItemType::Link);
    expect($item->metadata['url'])->toBe('https://figma.com/x');
    expect($story->fresh()->revision)->toBe($beforeRev + 1);
    expect($story->includedContextItems()->whereKey($item->id)->exists())->toBeTrue();
});

test('upload story-scoped file persists, auto-includes, and bumps revision once', function () {
    Storage::fake('private');
    [$story, $member] = storyPanelScene();
    $beforeRev = $story->fresh()->revision;

    $file = UploadedFile::fake()->create('story.pdf', 8, 'application/pdf');

    Livewire::actingAs($member)
        ->test('pages::context-items.story-assets-panel', ['storyId' => $story->id])
        ->set('newType', 'file')
        ->set('newTitle', 'Story PDF')
        ->set('newFile', $file)
        ->call('create')
        ->assertHasNoErrors();

    $item = $story->ownedContextItems()->first();
    expect($item->type)->toBe(ContextItemType::File);
    expect($item->story_id)->toBe($story->id);
    Storage::disk('private')->assertExists($item->metadata['path']);

    // Story-scoped file uploads now follow the same contract as text/link
    // creation: revision bumps once and the item auto-includes into the
    // Story's selection.
    expect($story->fresh()->revision)->toBe($beforeRev + 1);
    expect($story->includedContextItems()->whereKey($item->id)->exists())->toBeTrue();
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
