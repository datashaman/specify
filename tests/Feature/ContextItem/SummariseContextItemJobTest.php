<?php

use App\Ai\Agents\ContextSummariser;
use App\Enums\ContextItemSummaryStatus;
use App\Jobs\SummariseContextItemJob;
use App\Models\ContextItem;
use App\Models\User;
use App\Models\UserAiCredential;
use App\Services\Ai\ContextCompressor;

test('job marks item Skipped when creator has no BYOK creds', function () {
    $creator = User::factory()->create();
    $item = ContextItem::factory()->forText(str_repeat('lorem ipsum ', 200))->create([
        'created_by_id' => $creator->id,
        'summary_status' => ContextItemSummaryStatus::Pending,
    ]);

    (new SummariseContextItemJob($item->id))->handle(app(ContextCompressor::class));

    $fresh = $item->fresh();
    expect($fresh->summary_status)->toBe(ContextItemSummaryStatus::Skipped);
    expect($fresh->summary)->toBeNull();
});

test('job marks item Skipped when creator is null', function () {
    $item = ContextItem::factory()->forText(str_repeat('content ', 200))->create([
        'created_by_id' => null,
        'summary_status' => ContextItemSummaryStatus::Pending,
    ]);

    (new SummariseContextItemJob($item->id))->handle(app(ContextCompressor::class));

    expect($item->fresh()->summary_status)->toBe(ContextItemSummaryStatus::Skipped);
});

test('job writes Ready summary when agent returns text and creator has creds', function () {
    ContextSummariser::fake(fn () => 'short summary text');

    $creator = User::factory()->create(['ai_provider' => UserAiCredential::PROVIDER_ANTHROPIC]);
    UserAiCredential::create([
        'user_id' => $creator->id,
        'provider' => UserAiCredential::PROVIDER_ANTHROPIC,
        'api_key' => 'sk-test',
        'enabled' => true,
    ]);

    $item = ContextItem::factory()->forText(str_repeat('long body ', 300))->create([
        'created_by_id' => $creator->id,
        'summary_status' => ContextItemSummaryStatus::Pending,
    ]);

    (new SummariseContextItemJob($item->id))->handle(app(ContextCompressor::class));

    $fresh = $item->fresh();
    expect($fresh->summary_status)->toBe(ContextItemSummaryStatus::Ready);
    expect($fresh->summary)->toBe('short summary text');
});

test('job marks Skipped when item has no compressible body', function () {
    $creator = User::factory()->create();
    $item = ContextItem::factory()->forText('')->create([
        'created_by_id' => $creator->id,
        'metadata' => ['body' => ''],
        'summary_status' => ContextItemSummaryStatus::Pending,
    ]);

    (new SummariseContextItemJob($item->id))->handle(app(ContextCompressor::class));

    expect($item->fresh()->summary_status)->toBe(ContextItemSummaryStatus::Skipped);
});

test('job is a no-op when ContextItem no longer exists', function () {
    (new SummariseContextItemJob(999999))->handle(app(ContextCompressor::class));

    expect(true)->toBeTrue();
});

test('failed() callback marks the item Failed', function () {
    $item = ContextItem::factory()->forText('hi')->create([
        'summary_status' => ContextItemSummaryStatus::Pending,
    ]);

    (new SummariseContextItemJob($item->id))->failed(new RuntimeException('worker died'));

    $fresh = $item->fresh();
    expect($fresh->summary_status)->toBe(ContextItemSummaryStatus::Failed);
    expect($fresh->summary_error)->toBe('worker died');
});
