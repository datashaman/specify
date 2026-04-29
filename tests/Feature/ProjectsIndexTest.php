<?php

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

test('lists accessible projects with feature, repo, story counts', function () {
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    Project::factory()->for($team)->create(['name' => 'Visible Project']);

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    Project::factory()->for($otherTeam)->create(['name' => 'Hidden Project']);

    $this->actingAs($user);

    Livewire::test('pages::projects.index')
        ->assertSee('Visible Project')
        ->assertDontSee('Hidden Project');
});

test('redirects guests', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});
