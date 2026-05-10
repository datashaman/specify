<?php

use App\Enums\ContextItemType;
use App\Jobs\SummariseContextItemJob;
use App\Models\ContextItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Context\AssetUploader;
use App\Services\Context\ContextItemWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Bus::fake();
    config(['specify.context.assets.summary_threshold_chars' => 100]);
});

test('createProjectItem dispatches summarise when text body exceeds threshold', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->create();

    $item = app(ContextItemWriter::class)->createProjectItem($project, [
        'type' => ContextItemType::Text,
        'title' => 'Spec',
        'metadata' => ['body' => str_repeat('a', 500)],
    ], $actor);

    Bus::assertDispatched(SummariseContextItemJob::class, fn ($job) => $job->contextItemId === $item->id);
});

test('createProjectItem skips dispatch and marks Skipped for short text', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->create();

    $item = app(ContextItemWriter::class)->createProjectItem($project, [
        'type' => ContextItemType::Text,
        'title' => 'Tiny',
        'metadata' => ['body' => 'short'],
    ], $actor);

    Bus::assertNotDispatched(SummariseContextItemJob::class);
    expect($item->fresh()->summary_status->value)->toBe('skipped');
});

test('update on text item with new long body resets status and dispatches', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->create();
    $item = ContextItem::factory()->for($project)->forText('original')->create([
        'summary' => 'old summary',
        'summary_status' => 'ready',
    ]);

    app(ContextItemWriter::class)->update($item, [
        'metadata' => ['body' => str_repeat('z', 500)],
    ], $actor);

    Bus::assertDispatched(SummariseContextItemJob::class, fn ($job) => $job->contextItemId === $item->id);

    $fresh = $item->fresh();
    expect($fresh->summary)->toBeNull();
    expect($fresh->summary_status->value)->toBe('pending');
});

test('update without body change does not dispatch', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->create();
    $item = ContextItem::factory()->for($project)->forText('original')->create([
        'summary' => 'old summary',
        'summary_status' => 'ready',
    ]);

    app(ContextItemWriter::class)->update($item, ['title' => 'New title only'], $actor);

    Bus::assertNotDispatched(SummariseContextItemJob::class);
    expect($item->fresh()->summary_status->value)->toBe('ready');
});

test('AssetUploader does not dispatch summarise — file extraction is future work', function () {
    Storage::fake('private');
    $project = Project::factory()->create();
    $actor = User::factory()->create();

    $file = UploadedFile::fake()->create('notes.txt', 4, 'text/plain');

    $item = app(AssetUploader::class)->store($file, $project, null, $actor);

    // No extraction pipeline yet, so files land Skipped and the job stays
    // off the queue. The future extractor will flip status to Pending and
    // dispatch from there.
    Bus::assertNotDispatched(SummariseContextItemJob::class);
    expect($item->summary_status->value)->toBe('skipped');
});
