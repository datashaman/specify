<?php

use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
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

test('plan show uses plan-task language for unmapped tasks', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    AcceptanceCriterion::factory()->for($story)->create(['statement' => 'mapped behavior']);
    $task = Task::factory()->forCurrentPlanOf($story)->create([
        'name' => 'cross-cutting task',
        'acceptance_criterion_id' => null,
    ]);
    $task->plan->forceFill([
        'name' => 'Current implementation plan',
        'status' => PlanStatus::PendingApproval->value,
    ])->save();

    $this->actingAs($user);

    Livewire::test('pages::plans.show', [
        'project' => $project->id,
        'plan' => $task->plan->id,
    ])
        ->assertSee('Plan tasks')
        ->assertSee('1 plan tasks')
        ->assertSee('No plan task mapped to this AC.')
        ->assertSee('Plan tasks not mapped to an AC')
        ->assertSee('cross-cutting task');
});
