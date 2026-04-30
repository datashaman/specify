<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
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
    $task = Task::factory()->for($story)->create();

    return compact('user', 'project', 'feature', 'story', 'task');
}

test('runs page redirects unauthenticated users', function () {
    $this->get(route('runs.index'))->assertRedirect(route('login'));
});

test('lists runs scoped to the user\'s teams', function () {
    ['user' => $user, 'task' => $task] = runScene();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'visible-sub']);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_url' => 'https://github.com/o/r/pull/1'],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')
        ->assertSee('visible-sub')
        ->assertSee('https://github.com/o/r/pull/1');
});

test('does not show runs from outside the user\'s teams', function () {
    ['user' => $user] = runScene();

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $otherStory = Story::factory()->for($otherFeature)->create();
    $otherTask = Task::factory()->for($otherStory)->create();
    $hiddenSub = Subtask::factory()->for($otherTask)->create(['name' => 'hidden-sub']);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $hiddenSub->id,
        'status' => AgentRunStatus::Succeeded,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')->assertDontSee('hidden-sub');
});

test('shows PR merged badge when webhook recorded a merge', function () {
    ['user' => $user, 'task' => $task] = runScene();
    $sub = Subtask::factory()->for($task)->create(['name' => 'merged-sub']);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $sub->id,
        'status' => AgentRunStatus::Succeeded,
        'output' => [
            'pull_request_url' => 'https://github.com/o/r/pull/9',
            'pull_request_merged' => true,
            'pull_request_action' => 'closed',
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')
        ->assertSee('merged-sub')
        ->assertSee('PR merged');
});

test('shows repo name and tokens on the run card', function () {
    ['user' => $user, 'task' => $task, 'project' => $project] = runScene();
    $workspace = $project->team->workspace;
    $repo = Repo::factory()->for($workspace)->create(['name' => 'backend']);
    $project->attachRepo($repo);

    $sub = Subtask::factory()->for($task)->create(['name' => 'cost-sub']);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $sub->id,
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
    ['user' => $user, 'task' => $task] = runScene();
    $a = Subtask::factory()->for($task)->create(['name' => 'sub-running']);
    $b = Subtask::factory()->for($task)->create(['name' => 'sub-failed']);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $a->id,
        'status' => AgentRunStatus::Running,
    ]);
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $b->id,
        'status' => AgentRunStatus::Failed,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::runs.index')
        ->assertSee('sub-running')
        ->assertSee('sub-failed')
        ->set('status', 'failed')
        ->assertSee('sub-failed')
        ->assertDontSee('sub-running');
});
