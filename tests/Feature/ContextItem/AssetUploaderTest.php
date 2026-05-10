<?php

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\Context\AssetUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Bus::fake();
});

test('store persists file and creates a project-scoped ContextItem', function () {
    $project = Project::factory()->create();
    $actor = User::factory()->create();
    $file = UploadedFile::fake()->create('Spec Notes.pdf', 12, 'application/pdf');

    $item = app(AssetUploader::class)->store($file, $project, null, $actor);

    expect($item->type)->toBe(ContextItemType::File);
    expect($item->project_id)->toBe($project->id);
    expect($item->story_id)->toBeNull();
    expect($item->summary_status)->toBe(ContextItemSummaryStatus::Pending);
    expect($item->created_by_id)->toBe($actor->id);
    expect($item->title)->toBe('Spec Notes.pdf');
    expect($item->metadata['original_name'])->toBe('Spec Notes.pdf');
    expect($item->metadata['disk'])->toBe('private');

    Storage::disk('private')->assertExists($item->metadata['path']);
});

test('store accepts a Story when it belongs to the same Project', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $actor = User::factory()->create();

    $item = app(AssetUploader::class)->store(
        UploadedFile::fake()->create('a.pdf', 4, 'application/pdf'),
        $project,
        $story,
        $actor,
    );

    expect($item->story_id)->toBe($story->id);
    expect($item->project_id)->toBe($project->id);
});

test('store rejects a Story from a different Project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $featureB = Feature::factory()->for($projectB)->create();
    $story = Story::factory()->for($featureB)->create();
    $actor = User::factory()->create();

    expect(fn () => app(AssetUploader::class)->store(
        UploadedFile::fake()->create('a.pdf', 4, 'application/pdf'),
        $projectA,
        $story,
        $actor,
    ))->toThrow(InvalidArgumentException::class);
});

test('store rejects files exceeding the size cap', function () {
    config(['specify.context.assets.max_file_kb' => 1]);

    $project = Project::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => app(AssetUploader::class)->store(
        UploadedFile::fake()->create('big.pdf', 100, 'application/pdf'),
        $project,
        null,
        $actor,
    ))->toThrow(InvalidArgumentException::class);
});

test('store rejects MIME types not in the allow-list', function () {
    config(['specify.context.assets.allowed_mimes' => ['application/pdf']]);

    $project = Project::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => app(AssetUploader::class)->store(
        UploadedFile::fake()->create('a.exe', 4, 'application/x-msdownload'),
        $project,
        null,
        $actor,
    ))->toThrow(InvalidArgumentException::class);
});
