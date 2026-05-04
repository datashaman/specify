<?php

use App\Enums\AgentRunStatus;
use App\Enums\TeamRole;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

function subtaskScene(): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Owner);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $task = Task::factory()->forCurrentPlanOf($story)->create(['name' => 'parent-task', 'position' => 1]);
    $subtask = Subtask::factory()->for($task)->create(['name' => 'parent-subtask', 'position' => 1]);

    return compact('user', 'project', 'feature', 'story', 'task', 'subtask');
}

test('subtask page renders breadcrumb and subtask details', function () {
    $s = subtaskScene();
    $this->actingAs($s['user']);

    Livewire::test('pages::subtasks.show', [
        'project' => $s['project']->id,
        'story' => $s['story']->id,
        'subtask' => $s['subtask']->id,
    ])
        ->assertSee('parent-subtask')
        ->assertSee('parent-task')
        ->assertSeeHtml('data-section="breadcrumb"')
        ->assertSeeHtml('data-section="runs"');
});

test('subtask page shows race-mode badge when there are >1 runs', function () {
    $s = subtaskScene();
    AgentRun::factory()->for($s['subtask'], 'runnable')->create(['executor_driver' => 'specify', 'status' => AgentRunStatus::Succeeded]);
    AgentRun::factory()->for($s['subtask'], 'runnable')->create(['executor_driver' => 'claude-code', 'status' => AgentRunStatus::Failed]);

    $this->actingAs($s['user']);

    Livewire::test('pages::subtasks.show', [
        'project' => $s['project']->id,
        'story' => $s['story']->id,
        'subtask' => $s['subtask']->id,
    ])
        ->assertSee('Race · 2 drivers');
});

test('run console renders breadcrumb, status pill, and tab controls', function () {
    $s = subtaskScene();
    $run = AgentRun::factory()->for($s['subtask'], 'runnable')->create([
        'status' => AgentRunStatus::Succeeded,
        'executor_driver' => 'specify',
    ]);

    $this->actingAs($s['user']);

    Livewire::test('pages::runs.show', [
        'project' => $s['project']->id,
        'story' => $s['story']->id,
        'subtask' => $s['subtask']->id,
        'run' => $run->id,
    ])
        ->assertSeeHtml('data-section="breadcrumb"')
        ->assertSeeHtml('data-section="run-tabs"')
        ->assertSee('Run #'.$run->id)
        ->assertSee('Logs')
        ->assertSee('Diff')
        ->assertSee('Pull request');
});

test('run console 404s via HTTP when project URL doesnt match the runs project', function () {
    $s = subtaskScene();
    $run = AgentRun::factory()->for($s['subtask'], 'runnable')->create();
    $otherProject = Project::factory()->for($s['user']->teams()->first())->create();

    $this->actingAs($s['user'])
        ->get("/projects/{$otherProject->id}/stories/{$s['story']->id}/subtasks/{$s['subtask']->id}/runs/{$run->id}")
        ->assertNotFound();
});
