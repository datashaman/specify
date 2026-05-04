<?php

use App\Models\AgentRun;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('canonical workspace and project routes resolve directly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(route('triage'))->toEndWith('/triage')
        ->and(route('activity.index'))->toEndWith('/activity')
        ->and(route('projects.index'))->toEndWith('/projects');
});

test('record detail routes require project-scoped urls', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $task = Task::factory()->forCurrentPlanOf($story)->create();
    $subtask = Subtask::factory()->for($task)->create();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
    ]);

    $this->actingAs($user);

    expect(route('stories.show', ['project' => $project->id, 'story' => $story->id]))
        ->toEndWith("/projects/{$project->id}/stories/{$story->id}")
        ->and(route('runs.show', [
            'project' => $project->id,
            'story' => $story->id,
            'subtask' => $subtask->id,
            'run' => $run->id,
        ]))
        ->toEndWith("/projects/{$project->id}/stories/{$story->id}/subtasks/{$subtask->id}/runs/{$run->id}");
});
