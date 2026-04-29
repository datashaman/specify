<?php

use App\Enums\StoryStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
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
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    return compact('user', 'project', 'feature');
}

test('lists stories scoped to user teams', function () {
    ['user' => $user, 'feature' => $feature] = storyIndexScene();
    Story::factory()->for($feature)->create(['name' => 'mine', 'status' => StoryStatus::Approved]);

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    Story::factory()->for($otherFeature)->create(['name' => 'theirs', 'status' => StoryStatus::Approved]);

    $this->actingAs($user);

    Livewire::test('pages::stories.index')
        ->assertSee('mine')
        ->assertDontSee('theirs');
});

test('status filter narrows the list', function () {
    ['user' => $user, 'feature' => $feature] = storyIndexScene();
    Story::factory()->for($feature)->create(['name' => 'draft-story', 'status' => StoryStatus::Draft]);
    Story::factory()->for($feature)->create(['name' => 'approved-story', 'status' => StoryStatus::Approved]);

    $this->actingAs($user);

    Livewire::test('pages::stories.index')
        ->assertSee('draft-story')
        ->assertSee('approved-story')
        ->set('status', 'approved')
        ->assertSee('approved-story')
        ->assertDontSee('draft-story');
});

test('redirects guests', function () {
    $this->get(route('stories.index'))->assertRedirect(route('login'));
});
