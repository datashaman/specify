<?php

use App\Models\AcceptanceCriterion;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\PullRequests\PrPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSubtaskWithCriterion(int $acPosition = 3, ?string $criterion = 'Users can export their data as CSV.'): Subtask
{
    $story = Story::factory()->create(['name' => 'Add CSV export']);
    $ac = AcceptanceCriterion::factory()->for($story)->create([
        'position' => $acPosition,
        'statement' => $criterion,
    ]);
    $task = Task::factory()->forCurrentPlanOf($story)->create([
        'name' => 'Wire export endpoint',
        'position' => 1,
        'acceptance_criterion_id' => $ac->id,
    ]);

    return Subtask::factory()->for($task)->create([
        'name' => 'Add export route and controller',
        'position' => 1,
    ]);
}

test('title locates the subtask in the Story / AC tree', function () {
    $subtask = makeSubtaskWithCriterion(acPosition: 3);

    expect(PrPayloadBuilder::title($subtask))
        ->toBe(sprintf('Specify [Story #%d AC#%d]: Add export route and controller', $subtask->task->plan->story_id, 3));
});

test('title renders AC#0 instead of dropping the AC tag (regression: truthiness check)', function () {
    $subtask = makeSubtaskWithCriterion(acPosition: 0);

    expect(PrPayloadBuilder::title($subtask))
        ->toContain('AC#0');
});

test('title falls back to Story tag when AC position is null', function () {
    $story = Story::factory()->create();
    $task = Task::factory()->forCurrentPlanOf($story)->create([
        'name' => 'task',
        'position' => 1,
        'acceptance_criterion_id' => null,
    ]);
    $subtask = Subtask::factory()->for($task)->create(['name' => 'do work']);

    expect(PrPayloadBuilder::title($subtask))
        ->toBe(sprintf('Specify [Story #%d]: do work', $story->getKey()));
});

test('body renders Story, AC, summary, files, and Specify footer', function () {
    $subtask = makeSubtaskWithCriterion();

    $body = PrPayloadBuilder::body($subtask, [
        'summary' => 'Wired the export endpoint and added the controller test.',
        'files_changed' => ['app/Http/Controllers/ExportController.php', 'routes/web.php'],
    ]);

    expect($body)
        ->toContain('## Story', 'Add CSV export')
        ->and($body)->toContain('## Acceptance Criterion', 'Users can export their data as CSV.')
        ->and($body)->toContain('## What changed', 'Wired the export endpoint')
        ->and($body)->toContain('## Files', '`app/Http/Controllers/ExportController.php`', '`routes/web.php`')
        ->and($body)->toContain('Specify: human approval recorded on the current Plan');
});

test('body renders an Open questions section when clarifications are present', function () {
    $subtask = makeSubtaskWithCriterion();

    $body = PrPayloadBuilder::body($subtask, [
        'summary' => 'done',
        'clarifications' => [
            ['kind' => 'ambiguity', 'message' => 'Should exports include archived users?'],
            ['kind' => 'conflict', 'message' => 'AC #3 conflicts with the rate-limit policy in ADR-0007.'],
        ],
    ]);

    expect($body)
        ->toContain('## Open questions')
        ->and($body)->toContain('[ambiguity] Should exports include archived users?')
        ->and($body)->toContain('[conflict] AC #3 conflicts with the rate-limit policy');
});

test('body clamps a giant summary so the PR body stays under provider limits', function () {
    $subtask = makeSubtaskWithCriterion();

    $body = PrPayloadBuilder::body($subtask, [
        'summary' => str_repeat("line of agent output\n", 2_000),
    ]);

    expect(strlen($body))->toBeLessThan(12_000)
        ->and($body)->toContain('summary truncated');
});

test('body renders no Files section when none changed', function () {
    $subtask = makeSubtaskWithCriterion();

    $body = PrPayloadBuilder::body($subtask, ['summary' => 'nothing to file']);

    expect($body)->not->toContain('## Files');
});
