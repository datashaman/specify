<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function runScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $plan = Plan::factory()->for($story)->create();

    return compact('user', 'project', 'feature', 'story', 'plan');
}

test('runs page redirects unauthenticated users', function () {
    $this->get(route('runs.index'))->assertRedirect(route('login'));
});

test('lists runs scoped to the user\'s teams', function () {
    ['user' => $user, 'plan' => $plan] = runScene();
    $task = Task::factory()->for($plan)->create(['name' => 'visible-task']);
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $task->id,
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_url' => 'https://github.com/o/r/pull/1'],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')
        ->assertSee('visible-task')
        ->assertSee('https://github.com/o/r/pull/1');
});

test('does not show runs from outside the user\'s teams', function () {
    ['user' => $user] = runScene();

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $otherStory = Story::factory()->for($otherFeature)->create();
    $otherPlan = Plan::factory()->for($otherStory)->create();
    $hiddenTask = Task::factory()->for($otherPlan)->create(['name' => 'hidden-task']);
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $hiddenTask->id,
        'status' => AgentRunStatus::Succeeded,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')->assertDontSee('hidden-task');
});

test('shows PR merged badge when webhook recorded a merge', function () {
    ['user' => $user, 'plan' => $plan] = runScene();
    $task = Task::factory()->for($plan)->create(['name' => 'merged-task']);
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $task->id,
        'status' => AgentRunStatus::Succeeded,
        'output' => [
            'pull_request_url' => 'https://github.com/o/r/pull/9',
            'pull_request_merged' => true,
            'pull_request_action' => 'closed',
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')
        ->assertSee('merged-task')
        ->assertSee('PR merged');
});

test('shows repo name and tokens on the run card', function () {
    ['user' => $user, 'plan' => $plan, 'project' => $project] = runScene();
    $workspace = $project->team->workspace;
    $repo = Repo::factory()->for($workspace)->create(['name' => 'backend']);
    $project->attachRepo($repo);

    $task = Task::factory()->for($plan)->create(['name' => 'cost-task']);
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $task->id,
        'repo_id' => $repo->id,
        'status' => AgentRunStatus::Succeeded,
        'tokens_input' => 1234,
        'tokens_output' => 567,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')
        ->assertSee('backend')
        ->assertSee('1234/567 tok');
});

test('status filter narrows the list', function () {
    ['user' => $user, 'plan' => $plan] = runScene();
    $a = Task::factory()->for($plan)->create(['name' => 'task-running']);
    $b = Task::factory()->for($plan)->create(['name' => 'task-failed']);
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $a->id,
        'status' => AgentRunStatus::Running,
    ]);
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $b->id,
        'status' => AgentRunStatus::Failed,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')
        ->assertSee('task-running')
        ->assertSee('task-failed')
        ->set('status', 'failed')
        ->assertSee('task-failed')
        ->assertDontSee('task-running');
});
