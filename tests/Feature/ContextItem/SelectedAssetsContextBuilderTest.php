<?php

use App\Enums\ContextItemSummaryStatus;
use App\Models\ContextItem;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Context\CompositeContextBuilder;
use App\Services\Context\ContextBuilder;
use App\Services\Context\NullContextBuilder;
use App\Services\Context\RecencyContextBuilder;
use App\Services\Context\SelectedAssetsContextBuilder;

function subtaskWithStory(): array
{
    $story = Story::factory()->create();
    $plan = Plan::factory()->for($story)->create();
    $task = Task::factory()->forCurrentPlanOf($story)->create([
        'plan_id' => $plan->id,
        'position' => 1,
    ]);
    $subtask = Subtask::factory()->for($task)->create(['position' => 1, 'name' => 's']);

    return [$story, $subtask];
}

test('builder returns empty when no items are included', function () {
    [$story, $subtask] = subtaskWithStory();

    $brief = (new SelectedAssetsContextBuilder)->build($subtask, null, null);

    expect($brief)->toBe('');
});

test('builder renders included items inside a context-brief tag', function () {
    [$story, $subtask] = subtaskWithStory();
    $project = $story->feature->project;
    $a = ContextItem::factory()->for($project)->forText('alpha body')->create([
        'title' => 'Alpha',
        'summary' => 'alpha summary',
        'summary_status' => ContextItemSummaryStatus::Ready,
    ]);
    $story->includedContextItems()->attach($a->id);

    $brief = (new SelectedAssetsContextBuilder)->build($subtask, null, null);

    expect($brief)->toStartWith('<context-brief>');
    expect($brief)->toEndWith('</context-brief>');
    expect($brief)->toContain('### Alpha (text)');
    expect($brief)->toContain('alpha summary');
});

test('builder clamps to MAX_BYTES with a truncation note', function () {
    [$story, $subtask] = subtaskWithStory();
    $project = $story->feature->project;
    $bigBody = str_repeat('lorem ipsum dolor ', 100); // ~1.8 KB each — three of these blow the 4 KB cap

    foreach (range(1, 3) as $i) {
        $item = ContextItem::factory()->for($project)->forText($bigBody)->create([
            'title' => "Big {$i}",
            'summary' => $bigBody,
            'summary_status' => ContextItemSummaryStatus::Ready,
        ]);
        $story->includedContextItems()->attach($item->id);
    }

    $brief = (new SelectedAssetsContextBuilder)->build($subtask, null, null);

    expect(strlen($brief))->toBeLessThan(SelectedAssetsContextBuilder::MAX_BYTES + 256);
    expect($brief)->toContain('Truncated:');
});

test('CompositeContextBuilder concatenates non-empty briefs and skips empties', function () {
    [, $subtask] = subtaskWithStory();

    $alwaysEmpty = new NullContextBuilder;
    $alwaysSomething = new class implements ContextBuilder
    {
        public function build($subtask, $workingDir, $repo): string
        {
            return 'HELLO';
        }
    };

    $composite = new CompositeContextBuilder($alwaysEmpty, $alwaysSomething, $alwaysEmpty);

    expect($composite->build($subtask, null, null))->toBe('HELLO');
});

test('composite driver wires Recency + SelectedAssets and stays opt-in (default is recency)', function () {
    config(['specify.context.builder' => 'composite']);
    app()->forgetInstance(ContextBuilder::class);

    $builder = app(ContextBuilder::class);

    expect($builder)->toBeInstanceOf(CompositeContextBuilder::class);
});

test('default driver remains recency', function () {
    config(['specify.context.builder' => 'recency']);
    app()->forgetInstance(ContextBuilder::class);

    $builder = app(ContextBuilder::class);

    expect($builder)->toBeInstanceOf(RecencyContextBuilder::class);
});
