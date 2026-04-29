<?php

use App\Enums\FeatureStatus;
use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AcceptanceCriterion;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('full hierarchy chains workspace through task', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $plan = Plan::factory()->for($story)->create();
    $task = Task::factory()->for($plan)->create();

    expect($task->plan->story->feature->project->team->workspace->id)->toBe($workspace->id)
        ->and($task->plan->story->feature->project->workspace->id)->toBe($workspace->id);
});

test('story can have multiple plans and track current', function () {
    $story = Story::factory()->create();
    $v1 = Plan::factory()->for($story)->create(['version' => 1]);
    $v2 = Plan::factory()->for($story)->create(['version' => 2]);

    $story->update(['current_plan_id' => $v2->id]);

    expect($story->plans)->toHaveCount(2)
        ->and($story->fresh()->currentPlan->id)->toBe($v2->id)
        ->and($story->fresh()->currentPlan->version)->toBe(2);
});

test('plan tasks come back ordered by position', function () {
    $plan = Plan::factory()->create();
    Task::factory()->for($plan)->create(['position' => 2, 'name' => 'second']);
    Task::factory()->for($plan)->create(['position' => 0, 'name' => 'first']);
    Task::factory()->for($plan)->create(['position' => 1, 'name' => 'middle']);

    expect($plan->tasks->pluck('name')->all())->toBe(['first', 'middle', 'second']);
});

test('story has ordered acceptance criteria that survive plan regeneration', function () {
    $story = Story::factory()->create();
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1, 'criterion' => 'second']);
    AcceptanceCriterion::factory()->for($story)->create(['position' => 0, 'criterion' => 'first']);

    Plan::factory()->for($story)->create(['version' => 1])->delete();
    Plan::factory()->for($story)->create(['version' => 2]);

    expect($story->acceptanceCriteria->pluck('criterion')->all())->toBe(['first', 'second']);
});

test('deleting story cascades to acceptance criteria', function () {
    $story = Story::factory()->create();
    $ac = AcceptanceCriterion::factory()->for($story)->create();

    $story->delete();

    expect(AcceptanceCriterion::find($ac->id))->toBeNull();
});

test('default statuses are set across the hierarchy', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->create();
    $story = Story::factory()->create();
    $task = Task::factory()->create();
    $ac = AcceptanceCriterion::factory()->create();

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($feature->status)->toBe(FeatureStatus::Proposed)
        ->and($story->status)->toBe(StoryStatus::Draft)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($ac->met)->toBeFalse();
});

test('status enums round-trip through the database', function () {
    $story = Story::factory()->create(['status' => StoryStatus::PendingApproval]);

    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);
});

test('acceptance criterion met flag is castable', function () {
    $ac = AcceptanceCriterion::factory()->create(['met' => true]);

    expect($ac->fresh()->met)->toBeTrue();
});

test('deleting workspace cascades to tasks via team', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $plan = Plan::factory()->for($story)->create();
    $task = Task::factory()->for($plan)->create();

    $workspace->delete();

    expect(Task::find($task->id))->toBeNull()
        ->and(Plan::find($plan->id))->toBeNull()
        ->and(Story::find($story->id))->toBeNull()
        ->and(Feature::find($feature->id))->toBeNull()
        ->and(Project::find($project->id))->toBeNull()
        ->and(Team::find($team->id))->toBeNull();
});
