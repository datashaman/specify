<?php

use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Executors\ExecutorClarification;
use App\Services\Executors\ProposedSubtask;
use App\Services\PlanWriter;
use App\Services\PullRequests\PrPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeApprovedTask(): Task
{
    $story = Story::factory()->create(['status' => StoryStatus::Approved, 'revision' => 1]);
    $ac = AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);

    return Task::factory()->for($story)->create([
        'name' => 'wire CSV export',
        'position' => 1,
        'acceptance_criterion_id' => $ac->id,
    ]);
}

test('PlanWriter::appendProposedSubtasks appends without resetting Story approval (ADR-0005 carve-out)', function () {
    $task = makeApprovedTask();
    Subtask::factory()->for($task)->create(['position' => 1, 'name' => 'first', 'status' => TaskStatus::Done]);
    $story = $task->story;

    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $task->subtasks()->first()->getKey(),
        'status' => 'succeeded',
    ]);

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
    $originalRevision = $story->fresh()->revision;

    $proposed = [
        new ProposedSubtask('add tests', 'add Pest test for export controller', 'no test coverage on the new endpoint'),
    ];

    $created = app(PlanWriter::class)->appendProposedSubtasks($task, $proposed, $run);

    expect($created)->toHaveCount(1)
        ->and($created[0]->position)->toBe(2)
        ->and($created[0]->proposed_by_run_id)->toBe($run->getKey())
        ->and($created[0]->description)->toContain('_Reason:_ no test coverage')
        ->and($story->fresh()->status)->toBe(StoryStatus::Approved)
        ->and($story->fresh()->revision)->toBe($originalRevision);
});

test('PlanWriter::appendProposedSubtasks caps at 3 entries with a warning', function () {
    $task = makeApprovedTask();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => 1,
        'status' => 'succeeded',
    ]);

    $proposed = array_map(
        fn ($i) => new ProposedSubtask("step $i", "do step $i", "needed because $i"),
        range(1, 5),
    );

    $created = app(PlanWriter::class)->appendProposedSubtasks($task, $proposed, $run);

    expect($created)->toHaveCount(3);
});

test('PlanWriter::appendProposedSubtasks numbers new positions after the highest existing', function () {
    $task = makeApprovedTask();
    Subtask::factory()->for($task)->create(['position' => 7]);
    Subtask::factory()->for($task)->create(['position' => 12]);

    $run = AgentRun::create(['runnable_type' => Subtask::class, 'runnable_id' => 1, 'status' => 'succeeded']);

    $created = app(PlanWriter::class)->appendProposedSubtasks(
        $task,
        [new ProposedSubtask('next', 'do next', 'because')],
        $run,
    );

    expect($created[0]->position)->toBe(13);
});

test('ExecutorClarification ignores entries with unknown kind or empty message', function () {
    expect(ExecutorClarification::fromArray(['kind' => 'invalid', 'message' => 'x']))->toBeNull()
        ->and(ExecutorClarification::fromArray(['kind' => 'ambiguity', 'message' => '']))->toBeNull()
        ->and(ExecutorClarification::fromArray(['kind' => 'ambiguity', 'message' => 'real']))
        ->toBeInstanceOf(ExecutorClarification::class);
});

test('ProposedSubtask requires name, description, and reason', function () {
    expect(ProposedSubtask::fromArray(['name' => 'x', 'description' => 'y']))->toBeNull()
        ->and(ProposedSubtask::fromArray(['name' => '', 'description' => 'y', 'reason' => 'z']))->toBeNull()
        ->and(ProposedSubtask::fromArray(['name' => 'x', 'description' => 'y', 'reason' => 'z']))
        ->toBeInstanceOf(ProposedSubtask::class);
});

test('PR body renders the Proposed follow-up subtasks section when the pipeline appended any', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'main work']);

    $body = PrPayloadBuilder::body($subtask, [
        'summary' => 'done',
        'appended_subtasks' => [
            ['id' => 99, 'position' => 2, 'name' => 'add tests'],
            ['id' => 100, 'position' => 3, 'name' => 'document endpoint'],
        ],
    ]);

    expect($body)
        ->toContain('## Proposed follow-up subtasks')
        ->toContain('automatically (ADR-0005)')
        ->toContain('#2 — add tests')
        ->toContain('#3 — document endpoint');
});

test('PR body renders clarifications with their proposed alternative when present', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'main']);

    $body = PrPayloadBuilder::body($subtask, [
        'summary' => 'done',
        'clarifications' => [
            [
                'kind' => 'disagreement',
                'message' => 'Subtask says use synchronous IO; the rest of the codebase uses queued jobs.',
                'proposed' => 'Switch to a queued job for consistency.',
            ],
        ],
    ]);

    expect($body)
        ->toContain('[disagreement]')
        ->toContain('synchronous IO')
        ->toContain('_Proposed:_ Switch to a queued job');
});
