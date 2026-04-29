<?php

use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function featureShowScene(): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create(['name' => 'Approval inbox']);

    return compact('user', 'project', 'feature');
}

test('feature page lists its stories and links to a feature-prefilled create', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = featureShowScene();
    Story::factory()->for($feature)->create(['name' => 'Inline-listed-story']);

    $this->actingAs($user);

    Livewire::test('pages::features.show', ['project' => $project->id, 'feature' => $feature->id])
        ->assertSee('Approval inbox')
        ->assertSee('Inline-listed-story');
});

test('out-of-scope feature 404s', function () {
    ['user' => $user, 'project' => $project] = featureShowScene();

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();

    $this->actingAs($user);

    Livewire::test('pages::features.show', ['project' => $project->id, 'feature' => $otherFeature->id])
        ->assertStatus(404);
});
