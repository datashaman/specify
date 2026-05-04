<?php

use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('task can depend on another task in the same current plan', function () {
    $story = Story::factory()->create();
    $a = Task::factory()->forCurrentPlanOf($story)->create(['name' => 'a']);
    $b = Task::factory()->forCurrentPlanOf($story)->create(['name' => 'b']);

    $b->addDependency($a);

    expect($b->dependencies->pluck('name')->all())->toBe(['a'])
        ->and($a->dependents->pluck('name')->all())->toBe(['b']);
});

test('task dependencies are rejected across plans', function () {
    $a = Task::factory()->create();
    $b = Task::factory()->create();

    expect(fn () => $b->addDependency($a))
        ->toThrow(InvalidArgumentException::class, 'same plan');
});

test('task self-dependency is rejected', function () {
    $a = Task::factory()->create();

    expect(fn () => $a->addDependency($a))
        ->toThrow(InvalidArgumentException::class, 'cannot depend on itself');
});

test('task dependency cycle is prevented', function () {
    $story = Story::factory()->create();
    $a = Task::factory()->forCurrentPlanOf($story)->create();
    $b = Task::factory()->forCurrentPlanOf($story)->create();
    $c = Task::factory()->forCurrentPlanOf($story)->create();

    $b->addDependency($a);
    $c->addDependency($b);

    expect(fn () => $a->addDependency($c))
        ->toThrow(InvalidArgumentException::class, 'cycle');
});

test('task isReady reflects dependency status', function () {
    $story = Story::factory()->create();
    $a = Task::factory()->forCurrentPlanOf($story)->create(['status' => TaskStatus::Pending]);
    $b = Task::factory()->forCurrentPlanOf($story)->create(['status' => TaskStatus::Pending]);
    $b->addDependency($a);

    expect($b->isReady())->toBeFalse();

    $a->update(['status' => TaskStatus::Done]);

    expect($b->fresh()->isReady())->toBeTrue();
});

test('story precedence works across features within a workspace', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $authFeature = Feature::factory()->for($project)->create();
    $profileFeature = Feature::factory()->for($project)->create();

    $auth = Story::factory()->for($authFeature)->create();
    $profile = Story::factory()->for($profileFeature)->create();

    $profile->addDependency($auth);

    expect($profile->dependencies->pluck('id')->all())->toBe([$auth->id])
        ->and($profile->isReady())->toBeFalse();

    $auth->update(['status' => StoryStatus::Done]);

    expect($profile->fresh()->isReady())->toBeTrue();
});

test('story dependencies rejected across workspaces', function () {
    $a = Story::factory()->create();
    $b = Story::factory()->create();

    expect(fn () => $b->addDependency($a))
        ->toThrow(InvalidArgumentException::class, 'same workspace');
});

test('story dependency cycle is prevented', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $a = Story::factory()->for($feature)->create();
    $b = Story::factory()->for($feature)->create();
    $c = Story::factory()->for($feature)->create();

    $b->addDependency($a);
    $c->addDependency($b);

    expect(fn () => $a->addDependency($c))
        ->toThrow(InvalidArgumentException::class, 'cycle');
});
