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

test('/inbox 301-redirects to /triage', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/inbox')
        ->assertStatus(301)
        ->assertRedirect('/triage');
});

test('/events 301-redirects to /activity', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/events')
        ->assertStatus(301)
        ->assertRedirect('/activity');
});

test('triage and activity routes resolve', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(route('triage'))->toEndWith('/triage');
    expect(route('activity.index'))->toEndWith('/activity');
});

test('/stories 301-redirects to project-scoped url when current project is set', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $user->forceFill(['current_project_id' => $project->id])->save();

    $this->actingAs($user)
        ->get('/stories')
        ->assertStatus(301)
        ->assertRedirect("/projects/{$project->id}/stories");
});

test('/runs 301-redirects to project-scoped url when current project is set', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $user->forceFill(['current_project_id' => $project->id])->save();

    $this->actingAs($user)
        ->get('/runs')
        ->assertStatus(301)
        ->assertRedirect("/projects/{$project->id}/runs");
});

test('/repos 301-redirects to project-scoped url when current project is set', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $user->forceFill(['current_project_id' => $project->id])->save();

    $this->actingAs($user)
        ->get('/repos')
        ->assertStatus(301)
        ->assertRedirect("/projects/{$project->id}/repos");
});

test('legacy /stories falls back to projects index when no current project', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/stories')
        ->assertStatus(301)
        ->assertRedirect('/projects');
});

test('/stories/{id} 301-redirects to project-scoped url', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();

    $this->actingAs($user)
        ->get('/stories/'.$story->id)
        ->assertStatus(301)
        ->assertRedirect("/projects/{$project->id}/stories/{$story->id}");
});

test('/runs/{id} 301-redirects to canonical story document', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $task = Task::factory()->for($story)->create();
    $subtask = Subtask::factory()->for($task)->create();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
    ]);

    $this->actingAs($user)
        ->get('/runs/'.$run->id)
        ->assertStatus(301)
        ->assertRedirect("/projects/{$project->id}/stories/{$story->id}");
});
