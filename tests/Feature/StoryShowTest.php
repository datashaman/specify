<?php

use App\Enums\AgentRunStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function storyShowScene(): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    return compact('user', 'project', 'feature');
}

test('story show renders AC, plans with DAG, and runs', function () {
    ['user' => $user, 'feature' => $feature] = storyShowScene();
    $story = Story::factory()->for($feature)->create(['name' => 'visible-story']);
    AcceptanceCriterion::create([
        'story_id' => $story->id, 'position' => 0, 'criterion' => 'must-render-AC', 'met' => false,
    ]);
    $plan = Plan::factory()->for($story)->create(['version' => 1]);
    $task = Task::factory()->for($plan)->create(['name' => 'task-name', 'position' => 0]);
    AgentRun::factory()->create([
        'runnable_type' => Task::class,
        'runnable_id' => $task->id,
        'status' => AgentRunStatus::Succeeded,
        'output' => ['pull_request_url' => 'https://github.com/o/r/pull/9'],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::stories.show', ['story' => $story->id])
        ->assertSee('visible-story')
        ->assertSee('must-render-AC')
        ->assertSee('task-name')
        ->assertSee('https://github.com/o/r/pull/9');
});

test('out-of-scope story 404s', function () {
    ['user' => $user] = storyShowScene();

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $otherStory = Story::factory()->for($otherFeature)->create();

    $this->actingAs($user);

    Livewire::test('pages::stories.show', ['story' => $otherStory->id])
        ->assertStatus(404);
});
