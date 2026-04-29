<?php

use App\Enums\AgentRunStatus;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
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
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard counts pending stories, plans, executing plans, and failed runs in scope', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);
    $feature = Feature::factory()->for($project)->create();

    Story::factory()->count(2)->for($feature)->create(['status' => StoryStatus::PendingApproval]);
    $approved = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);

    Plan::factory()->for($approved)->create(['status' => PlanStatus::PendingApproval]);
    Plan::factory()->for($approved)->create(['status' => PlanStatus::Executing]);

    $taskWithFailedRun = Task::factory()->for(
        Plan::factory()->for($approved)->create(['status' => PlanStatus::Executing])
    )->create();
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $taskWithFailedRun->id,
        'status' => AgentRunStatus::Failed,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSet('pendingStoryCount', 2)
        ->assertSet('pendingPlanCount', 1)
        ->assertSet('executingPlanCount', 2)
        ->assertSet('failedRunCount', 1)
        ->assertSee('Specify');
});

test('dashboard ignores stories, plans, and runs from other teams', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    Story::factory()->for($otherFeature)->create(['status' => StoryStatus::PendingApproval]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')->assertSet('pendingStoryCount', 0);
});

test('dashboard surfaces repos missing an access_token', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create();
    $repo = Repo::factory()->for($ws)->create(['access_token' => null]);
    $project->attachRepo($repo);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')->assertSet('reposNeedingToken', 1);
});
