<?php

use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
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

test('plans index lists current plans for the selected project', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    $task = Task::factory()->for($story)->create(['position' => 1]);
    $task->plan->forceFill(['name' => 'Current plan', 'status' => PlanStatus::PendingApproval->value])->save();

    $this->actingAs($user);

    Livewire::test('pages::plans.index', ['project' => $project->id])
        ->assertSee('Plans')
        ->assertSee('Current plan')
        ->assertSee('implementation layer');
});
