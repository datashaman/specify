<?php

use App\Enums\AgentRunStatus;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
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

test('dashboard counts pending stories, pending plans, executing stories, and failed runs in scope', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);
    $feature = Feature::factory()->for($project)->create();

    Story::factory()->count(2)->for($feature)->create(['status' => StoryStatus::PendingApproval]);
    $approved = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    $task = Task::factory()->forStory($approved)->create();
    $task->plan->forceFill(['status' => PlanStatus::Approved->value])->save();

    $planPendingStory = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    $planPendingTask = Task::factory()->forStory($planPendingStory)->create();
    $planPendingTask->plan->forceFill(['status' => PlanStatus::PendingApproval->value])->save();
    Subtask::factory()->for($task)->create(['status' => TaskStatus::InProgress]);

    $approved2 = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    $taskWithFailedRun = Task::factory()->forStory($approved2)->create();
    $taskWithFailedRun->plan->forceFill(['status' => PlanStatus::Approved->value])->save();
    $subtaskWithFailedRun = Subtask::factory()->for($taskWithFailedRun)->create();
    AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtaskWithFailedRun->id,
        'status' => AgentRunStatus::Failed,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSet('pendingStoryCount', 2)
        ->assertSet('pendingPlanCount', 1)
        ->assertSet('executingStoryCount', 2)
        ->assertSet('failedRunCount', 1)
        ->assertSee('Specify');
});

test('dashboard ignores stories and runs from other teams', function () {
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

test('awaiting your approval lists pending stories and pending current plans the user can approve', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Admin);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $project = Project::factory()->for($team)->create(['name' => 'Specify']);
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create([
        'name' => 'Approve me',
        'status' => StoryStatus::PendingApproval,
    ]);
    $planStory = Story::factory()->for($feature)->create([
        'name' => 'Approve my plan',
        'status' => StoryStatus::Approved,
    ]);
    $planTask = Task::factory()->forStory($planStory)->create();
    $planTask->plan->forceFill(['status' => PlanStatus::PendingApproval->value])->save();

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSee('Story contracts awaiting your approval')
        ->assertSee('Current plans awaiting your approval')
        ->assertSee('Approve me')
        ->assertSee('Approve my plan')
        ->assertSeeHtml(route('stories.show', ['project' => $project->id, 'story' => $story->id]));
});

test('awaiting your approval is hidden for non-approvers', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Member);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    Story::factory()->for($feature)->create([
        'status' => StoryStatus::PendingApproval,
        'name' => 'Hidden from me',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertDontSee('Awaiting your approval')
        ->assertDontSee('Hidden from me');
});

test('recent runs are clickable and link to the run console', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $task = Task::factory()->forStory($story)->create();
    $subtask = Subtask::factory()->for($task)->create();
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->id,
        'status' => AgentRunStatus::Succeeded,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSeeHtml(route('runs.show', [
            'project' => $project->id,
            'story' => $story->id,
            'subtask' => $subtask->id,
            'run' => $run->id,
        ]));
});
