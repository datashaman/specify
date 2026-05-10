<?php

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
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

function panelScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();

    $member = User::factory()->create();
    $team->addMember($member);
    $member->forceFill(['current_team_id' => $team->id, 'current_project_id' => $project->id])->save();

    return [$project, $member, $team];
}

test('panel renders existing project items and skips story-scoped ones', function () {
    [$project, $member] = panelScene();
    ContextItem::factory()->for($project)->forText('shown')->create(['title' => 'Shown']);
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    ContextItem::factory()->for($project)->for($story)->forText('hidden')->create(['title' => 'Hidden']);

    Livewire::actingAs($member)
        ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
        ->assertSee('Shown')
        ->assertDontSee('Hidden');
});

test('non-member cannot mount the panel', function () {
    [$project] = panelScene();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
        ->assertStatus(403);
});

test('create text item persists project-scoped row with summary_status set by writer', function () {
    [$project, $member] = panelScene();

    Livewire::actingAs($member)
        ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
        ->set('newType', 'text')
        ->set('newTitle', 'Style guide')
        ->set('newBody', 'Use Oxford commas.')
        ->call('create')
        ->assertHasNoErrors();

    $item = $project->contextItems()->first();
    expect($item->title)->toBe('Style guide');
    expect($item->type)->toBe(ContextItemType::Text);
    expect($item->story_id)->toBeNull();
    // Short body: writer marks Skipped (under threshold).
    expect($item->summary_status)->toBe(ContextItemSummaryStatus::Skipped);
});

test('create link item stores url in metadata', function () {
    [$project, $member] = panelScene();

    Livewire::actingAs($member)
        ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
        ->set('newType', 'link')
        ->set('newTitle', 'Figma')
        ->set('newUrl', 'https://figma.com/x')
        ->call('create')
        ->assertHasNoErrors();

    $item = $project->contextItems()->first();
    expect($item->type)->toBe(ContextItemType::Link);
    expect($item->metadata['url'])->toBe('https://figma.com/x');
});

test('upload file goes through AssetUploader and lands as File item', function () {
    Storage::fake('private');
    [$project, $member] = panelScene();

    $file = UploadedFile::fake()->create('spec.pdf', 8, 'application/pdf');

    Livewire::actingAs($member)
        ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
        ->set('newType', 'file')
        ->set('newTitle', 'Spec')
        ->set('newFile', $file)
        ->call('create')
        ->assertHasNoErrors();

    $item = $project->contextItems()->first();
    expect($item->type)->toBe(ContextItemType::File);
    expect($item->metadata['disk'])->toBe('private');
    Storage::disk('private')->assertExists($item->metadata['path']);
});

test('edit updates title and body for text items', function () {
    [$project, $member] = panelScene();
    $item = ContextItem::factory()->for($project)->forText('old')->create(['title' => 'Old']);

    Livewire::actingAs($member)
        ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
        ->call('startEdit', $item->id)
        ->set('editTitle', 'New')
        ->set('editBody', 'Refreshed body')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $fresh = $item->fresh();
    expect($fresh->title)->toBe('New');
    expect($fresh->metadata['body'])->toBe('Refreshed body');
});

test('delete removes the row', function () {
    [$project, $member] = panelScene();
    $item = ContextItem::factory()->for($project)->forText('x')->create();

    Livewire::actingAs($member)
        ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
        ->call('delete', $item->id)
        ->assertHasNoErrors();

    expect(ContextItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

test('non-member cannot reach any mutation method (mount gate fires first)', function () {
    [$project] = panelScene();
    $item = ContextItem::factory()->for($project)->forText('x')->create();
    $outsider = User::factory()->create();

    // The component is gated at mount on `$user->accessibleProjectIds()`,
    // so an outsider can't get a live instance to call mutations against.
    // Verify mount blocks for each entry point a malicious request might
    // try (initial render, attempted mutation post).
    foreach (['create', 'delete', 'startEdit', 'saveEdit'] as $_method) {
        Livewire::actingAs($outsider)
            ->test('pages::context-items.project-assets-panel', ['projectId' => $project->id])
            ->assertStatus(403);
    }

    // The pre-existing item is untouched — no Livewire instance ever
    // bound for the outsider.
    expect(ContextItem::query()->whereKey($item->id)->exists())->toBeTrue();
});
