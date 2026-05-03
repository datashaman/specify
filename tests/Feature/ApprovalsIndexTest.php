<?php

use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Enums\TeamRole;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('approvals board shows separate story and plan queues', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Admin);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 1,
    ]);

    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::PendingApproval,
        'name' => 'Contract queue item',
    ]);
    $planStory = Story::factory()->for($feature)->create([
        'status' => StoryStatus::Approved,
        'name' => 'Plan queue item',
    ]);
    $task = Task::factory()->for($planStory)->create(['position' => 1]);
    $task->plan->forceFill(['status' => PlanStatus::PendingApproval->value, 'name' => 'Execution plan'])->save();

    $this->actingAs($user);

    Livewire::test('pages::approvals.index', ['project' => $project->id])
        ->assertSee('Approvals')
        ->assertSee('Story contracts pending approval')
        ->assertSee('Current plans pending approval')
        ->assertSee('Contract queue item')
        ->assertSee('Plan queue item')
        ->assertSee('Approve story contract')
        ->assertSee('Approve current plan');
});

test('approvals board requires approver rights', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Member);
    $project = Project::factory()->for($team)->create();

    $this->actingAs($user)
        ->get(route('approvals.index', ['project' => $project->id]))
        ->assertForbidden();
});
