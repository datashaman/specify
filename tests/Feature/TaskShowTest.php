<?php

use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\AcceptanceCriterion;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function taskShowScene(): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Admin);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);
    $feature = Feature::factory()->for($project)->create(['name' => 'Authoring']);
    $story = Story::factory()->for($feature)->create(['name' => 'Manage stories']);
    $ac = AcceptanceCriterion::create([
        'story_id' => $story->id,
        'position' => 1,
        'statement' => 'Users can edit a story.',
    ]);
    $task = Task::factory()->forStory($story)->create([
        'name' => 'Add edit form',
        'position' => 1,
        'status' => TaskStatus::InProgress,
        'acceptance_criterion_id' => $ac->id,
    ]);
    $subtask = Subtask::factory()->for($task)->create([
        'name' => 'Wire input fields',
        'position' => 1,
    ]);

    return compact('user', 'project', 'feature', 'story', 'ac', 'task', 'subtask');
}

test('task show page renders task name, AC, and subtask', function () {
    ['user' => $user, 'project' => $project, 'story' => $story, 'task' => $task] = taskShowScene();
    $this->actingAs($user);

    Livewire::test('pages::tasks.show', [
        'project' => $project->id,
        'story' => $story->id,
        'task' => $task->id,
    ])
        ->assertSee('Add edit form')
        ->assertSee('Users can edit a story.')
        ->assertSee('Wire input fields');
});

test('task show 404s when task is outside the user accessible projects', function () {
    ['user' => $user] = taskShowScene();

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $otherStory = Story::factory()->for($otherFeature)->create();
    $otherTask = Task::factory()->forStory($otherStory)->create();

    $this->actingAs($user);

    Livewire::test('pages::tasks.show', [
        'project' => $otherProject->id,
        'story' => $otherStory->id,
        'task' => $otherTask->id,
    ])->assertStatus(404);
});

test('task show mount syncs current_project_id', function () {
    ['user' => $user, 'project' => $project, 'story' => $story, 'task' => $task] = taskShowScene();
    $other = Project::factory()->for($project->team)->create();
    $user->forceFill(['current_project_id' => $other->id])->save();

    $this->actingAs($user);

    Livewire::test('pages::tasks.show', [
        'project' => $project->id,
        'story' => $story->id,
        'task' => $task->id,
    ]);

    expect($user->fresh()->current_project_id)->toBe($project->id);
});

test('story show page links task names to the task show page', function () {
    ['user' => $user, 'project' => $project, 'story' => $story, 'task' => $task] = taskShowScene();
    $this->actingAs($user);

    Livewire::test('pages::stories.show', ['story' => $story->id])
        ->assertSeeHtml(route('tasks.show', [
            'project' => $project->id,
            'story' => $story->id,
            'task' => $task->id,
        ]));
});
