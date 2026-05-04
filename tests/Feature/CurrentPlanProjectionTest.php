<?php

use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Models\AcceptanceCriterion;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('acceptance criterion met only reflects done tasks in the current plan', function () {
    $story = Story::factory()->create();
    $ac = AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);

    $oldTask = Task::factory()->forCurrentPlanOf($story)->create([
        'acceptance_criterion_id' => $ac->id,
        'status' => TaskStatus::Done,
        'position' => 1,
    ]);
    $oldPlan = $oldTask->plan;

    $newPlan = Plan::factory()->create([
        'story_id' => $story->id,
        'version' => 2,
        'status' => PlanStatus::PendingApproval,
    ]);
    $newTask = Task::factory()->create([
        'plan_id' => $newPlan->id,
        'acceptance_criterion_id' => $ac->id,
        'status' => TaskStatus::Pending,
        'position' => 1,
    ]);

    $story->forceFill(['current_plan_id' => $newPlan->id])->save();
    $oldPlan->forceFill(['status' => PlanStatus::Superseded->value])->save();

    expect($ac->fresh()->met)->toBeFalse();
});
