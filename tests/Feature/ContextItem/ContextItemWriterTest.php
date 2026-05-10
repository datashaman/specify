<?php

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
use App\Enums\StoryStatus;
use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\Context\ContextItemWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function ciScene(): array
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

test('createProjectItem creates project-scoped item without reopening any story', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = ciScene();

    $beforeRev = $story->fresh()->revision;

    $item = app(ContextItemWriter::class)->createProjectItem($project, [
        'type' => ContextItemType::Text,
        'title' => 'House style',
        'metadata' => ['body' => 'Use Oxford commas.'],
    ], $actor);

    expect($item->project_id)->toBe($project->id);
    expect($item->story_id)->toBeNull();
    expect($item->summary_status)->toBe(ContextItemSummaryStatus::Pending);
    expect($story->fresh()->revision)->toBe($beforeRev);
    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('createStoryItem auto-includes and reopens approval', function () {
    ['story' => $story, 'actor' => $actor] = ciScene();

    $item = app(ContextItemWriter::class)->createStoryItem($story, [
        'type' => ContextItemType::Text,
        'title' => 'Note',
        'metadata' => ['body' => 'Hot edit'],
    ], $actor);

    $fresh = $story->fresh();
    expect($item->story_id)->toBe($story->id);
    expect($fresh->revision)->toBe(2);
    expect($story->includedContextItems()->whereKey($item->id)->exists())->toBeTrue();
});

test('createProjectItem rejects file type', function () {
    ['project' => $project, 'actor' => $actor] = ciScene();

    expect(fn () => app(ContextItemWriter::class)->createProjectItem($project, [
        'type' => ContextItemType::File,
        'title' => 'x',
    ], $actor))->toThrow(InvalidArgumentException::class);
});

test('createProjectItem stores link with summary_status=skipped', function () {
    ['project' => $project, 'actor' => $actor] = ciScene();

    $item = app(ContextItemWriter::class)->createProjectItem($project, [
        'type' => ContextItemType::Link,
        'title' => 'Figma',
        'metadata' => ['url' => 'https://figma.com/x'],
    ], $actor);

    expect($item->summary_status)->toBe(ContextItemSummaryStatus::Skipped);
});

test('update on project-scoped item does not reopen approval', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = ciScene();
    $item = ContextItem::factory()->for($project)->forText('old')->create();
    $beforeRev = $story->fresh()->revision;

    app(ContextItemWriter::class)->update($item, ['title' => 'New title'], $actor);

    expect($item->fresh()->title)->toBe('New title');
    expect($story->fresh()->revision)->toBe($beforeRev);
});

test('update on story-scoped item reopens approval once', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = ciScene();
    $item = ContextItem::factory()->for($project)->for($story)->forText('old')->create();
    $beforeRev = $story->fresh()->revision;

    app(ContextItemWriter::class)->update($item, ['title' => 'Renamed'], $actor);

    expect($story->fresh()->revision)->toBe($beforeRev + 1);
});

test('update with no real changes does not save or reopen', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = ciScene();
    $item = ContextItem::factory()->for($project)->for($story)->forText('body')->create([
        'title' => 'Same',
    ]);
    $beforeRev = $story->fresh()->revision;

    app(ContextItemWriter::class)->update($item, ['title' => 'Same'], $actor);

    expect($story->fresh()->revision)->toBe($beforeRev);
});

test('delete on story-scoped item soft-deletes row, removes file, reopens approval', function () {
    Storage::fake('private');
    ['story' => $story, 'project' => $project, 'actor' => $actor] = ciScene();

    $file = UploadedFile::fake()->create('doc.pdf', 4, 'application/pdf');
    $stored = $file->storeAs('context/'.Str::ulid(), 'doc.pdf', ['disk' => 'private']);
    $item = ContextItem::factory()->for($project)->for($story)->forFile($stored, 'doc.pdf', 'application/pdf')->create();

    Storage::disk('private')->assertExists($stored);
    $beforeRev = $story->fresh()->revision;

    app(ContextItemWriter::class)->delete($item, $actor);

    expect(ContextItem::query()->whereKey($item->id)->exists())->toBeFalse();
    expect(ContextItem::withTrashed()->whereKey($item->id)->exists())->toBeTrue();
    Storage::disk('private')->assertMissing($stored);
    expect($story->fresh()->revision)->toBe($beforeRev + 1);
});

test('update refuses to mutate metadata on file-typed items', function () {
    Storage::fake('private');
    ['story' => $story, 'project' => $project, 'actor' => $actor] = ciScene();
    $stored = UploadedFile::fake()->create('doc.pdf', 4, 'application/pdf')->storeAs(
        'context/'.Str::ulid(), 'doc.pdf', ['disk' => 'private']
    );
    $item = ContextItem::factory()->for($project)->for($story)->forFile($stored, 'doc.pdf', 'application/pdf')->create();

    expect(fn () => app(ContextItemWriter::class)->update($item, [
        'metadata' => ['disk' => 'public', 'path' => 'someone/elses/file.txt'],
    ], $actor))->toThrow(InvalidArgumentException::class);

    // Title-only updates on file items remain allowed.
    app(ContextItemWriter::class)->update($item, ['title' => 'Renamed.pdf'], $actor);
    expect($item->fresh()->title)->toBe('Renamed.pdf');
});

test('delete refuses to delete from a non-configured disk', function () {
    Storage::fake('private');
    Storage::fake('public');
    ['story' => $story, 'project' => $project, 'actor' => $actor] = ciScene();

    $tampered = UploadedFile::fake()->create('canary.pdf', 4, 'application/pdf')->storeAs(
        'shared/canary', 'canary.pdf', ['disk' => 'public']
    );
    Storage::disk('public')->assertExists($tampered);

    // Item claims `public` disk in metadata but assets are configured to `private`.
    $item = ContextItem::factory()->for($project)->for($story)->create([
        'type' => ContextItemType::File,
        'metadata' => ['disk' => 'public', 'path' => $tampered, 'mime' => 'application/pdf'],
    ]);

    app(ContextItemWriter::class)->delete($item, $actor);

    // Row gone, but the canary on the wrong disk survives.
    expect(ContextItem::query()->whereKey($item->id)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($tampered);
});

test('delete on project-scoped item does not reopen any story', function () {
    ['project' => $project, 'story' => $story, 'actor' => $actor] = ciScene();
    $item = ContextItem::factory()->for($project)->forText()->create();
    $beforeRev = $story->fresh()->revision;

    app(ContextItemWriter::class)->delete($item, $actor);

    expect(ContextItem::query()->whereKey($item->id)->exists())->toBeFalse();
    expect($story->fresh()->revision)->toBe($beforeRev);
});
