<?php

use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function storyIndexScene(): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    return compact('user', 'project', 'feature');
}

test('lists stories scoped to user teams', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = storyIndexScene();
    Story::factory()->for($feature)->create(['name' => 'mine', 'status' => StoryStatus::Approved]);

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    Story::factory()->for($otherFeature)->create(['name' => 'theirs', 'status' => StoryStatus::Approved]);

    $this->actingAs($user);

    Livewire::test('pages::stories.index', ['project' => $project->id])
        ->assertSee('mine')
        ->assertDontSee('theirs');
});

test('status filter narrows the list', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = storyIndexScene();
    Story::factory()->for($feature)->create(['name' => 'draft-story', 'status' => StoryStatus::Draft]);
    Story::factory()->for($feature)->create(['name' => 'approved-story', 'status' => StoryStatus::Approved]);

    $this->actingAs($user);

    Livewire::test('pages::stories.index', ['project' => $project->id])
        ->assertSee('draft-story')
        ->assertSee('approved-story')
        ->set('status', 'approved')
        ->assertSee('approved-story')
        ->assertDontSee('draft-story');
});

test('story summary labels task progress as current-plan tasks', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = storyIndexScene();
    $story = Story::factory()->for($feature)->create(['name' => 'planned-story', 'status' => StoryStatus::Approved]);
    Task::factory()->forStory($story)->create(['status' => TaskStatus::Done]);
    Task::factory()->forStory($story)->create(['status' => TaskStatus::Pending]);

    $this->actingAs($user);

    Livewire::test('pages::stories.index', ['project' => $project->id])
        ->assertSee('planned-story')
        ->assertSee('1/2 current-plan tasks')
        ->assertDontSee('1/2 tasks');
});

test('redirects guests', function () {
    $this->get('/stories')->assertRedirect(route('login'));
});
