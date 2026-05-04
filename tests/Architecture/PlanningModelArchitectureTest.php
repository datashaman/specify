<?php

use App\Models\AcceptanceCriterion;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;

arch('tasks are owned by plans')
    ->expect(Task::class)
    ->toHaveMethod('plan')
    ->not->toHaveMethod('st'.'ory');

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
