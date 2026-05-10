<?php

use App\Ai\Agents\TasksGenerator;
use App\Enums\ContextItemSummaryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\ContextItem;
use App\Models\Story;

function tgScene(): Story
{
    $story = Story::factory()->create();
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1, 'statement' => 'first']);

    return $story;
}

test('buildPrompt omits the assets block when no items are included', function () {
    $story = tgScene();

    $prompt = (new TasksGenerator($story))->buildPrompt();

    expect($prompt)->not->toContain('## Selected context assets');
});

test('buildPrompt renders titles + bodies of included context assets', function () {
    $story = tgScene();
    $project = $story->feature->project;

    $a = ContextItem::factory()->for($project)->forText('alpha body')->create([
        'title' => 'Style guide',
        'summary' => 'STYLE SUMMARY',
        'summary_status' => ContextItemSummaryStatus::Ready,
    ]);
    $b = ContextItem::factory()->for($project)->forLink('https://figma.com/x')->create([
        'title' => 'Figma file',
    ]);
    $story->includedContextItems()->attach([$a->id, $b->id]);

    $prompt = (new TasksGenerator($story))->buildPrompt();

    expect($prompt)->toContain('## Selected context assets');
    expect($prompt)->toContain('### Style guide (text)');
    expect($prompt)->toContain('STYLE SUMMARY');
    expect($prompt)->toContain('### Figma file (link)');
    expect($prompt)->toContain('https://figma.com/x');
});

test('buildPrompt enforces the byte cap and notes truncation', function () {
    $story = tgScene();
    $project = $story->feature->project;

    $bigBody = str_repeat('lorem ipsum dolor sit amet ', 400); // ~10 KB
    $a = ContextItem::factory()->for($project)->forText($bigBody)->create([
        'title' => 'Big A',
        'summary' => $bigBody,
        'summary_status' => ContextItemSummaryStatus::Ready,
    ]);
    $b = ContextItem::factory()->for($project)->forText($bigBody)->create([
        'title' => 'Big B',
        'summary' => $bigBody,
        'summary_status' => ContextItemSummaryStatus::Ready,
    ]);
    $story->includedContextItems()->attach([$a->id, $b->id]);

    $prompt = (new TasksGenerator($story))->buildPrompt();

    expect($prompt)->toContain('## Selected context assets');
    expect($prompt)->toContain('Truncated:');

    // Block (between header and the closing-instructions tail) stays within
    // the cap. Use strrpos so a context body that happens to contain the
    // closing-instructions phrase doesn't mismeasure the block.
    $blockPosition = strpos($prompt, '## Selected context assets');
    $blockEnd = strrpos($prompt, 'Generate an implementation plan');
    expect($blockEnd - $blockPosition)->toBeLessThan(TasksGenerator::CONTEXT_ASSETS_CAP_BYTES + 256);
});

test('buildPrompt prefers summary over raw body when summary is ready', function () {
    $story = tgScene();
    $project = $story->feature->project;
    $item = ContextItem::factory()->for($project)->forText('the long raw body would normally appear')->create([
        'title' => 'House style',
        'summary' => 'condensed summary line',
        'summary_status' => ContextItemSummaryStatus::Ready,
    ]);
    $story->includedContextItems()->attach($item->id);

    $prompt = (new TasksGenerator($story))->buildPrompt();

    expect($prompt)->toContain('condensed summary line');
    expect($prompt)->not->toContain('the long raw body would normally appear');
});
