<?php

use App\Models\AcceptanceCriterion;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

arch('tasks are owned by plans')
    ->expect(Task::class)
    ->toHaveMethod('plan')
    ->not->toHaveMethod('st'.'ory');

arch('stories expose current plan tasks explicitly')
    ->expect(Story::class)
    ->toHaveMethod('currentPlanTasks')
    ->not->toHaveMethod('tasks');

test('tasks table stores plan ownership only', function () {
    expect(Schema::hasColumn('tasks', 'plan_id'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'st'.'ory_id'))->toBeFalse()
        ->and(Task::make()->plan()->getRelated())->toBeInstanceOf(Plan::class);
});

test('acceptance criteria persist statement content', function () {
    $columns = Schema::getColumnListing('acceptance_criteria');
    $retiredContentColumn = 'cri'.'terion';

    expect($columns)->toContain('statement')
        ->and($columns)->not->toContain($retiredContentColumn)
        ->and((new AcceptanceCriterion)->getFillable())->toContain('statement')
        ->and((new AcceptanceCriterion)->getFillable())->not->toContain($retiredContentColumn);
});

test('plan versions are unique within a story', function () {
    $story = Story::factory()->create();
    Plan::factory()->for($story)->create(['version' => 1]);

    Plan::factory()->for(Story::factory())->create(['version' => 1]);

    expect(fn () => Plan::factory()->for($story)->create(['version' => 1]))
        ->toThrow(QueryException::class);
});
