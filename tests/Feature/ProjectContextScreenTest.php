<?php

use App\Enums\TeamRole;
use App\Models\ContextItem;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function projectContextScreenScene(TeamRole $role = TeamRole::Admin): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);

    return compact('user', 'project');
}

test('admin can open the project context screen', function () {
    $this->withoutVite();

    ['user' => $user, 'project' => $project] = projectContextScreenScene();
    ContextItem::factory()->for($project)->create([
        'type' => 'repository',
        'title' => 'Primary repository',
        'description' => 'The implementation repository.',
    ]);
    ContextItem::factory()->create(['title' => 'Hidden context']);

    $this->actingAs($user);

    Livewire::test('pages::projects.context.index', ['project' => $project->id])
        ->assertSee('Project context')
        ->assertSee('Specify')
        ->assertSee('Primary repository')
        ->assertSee('The implementation repository.')
        ->assertDontSee('Hidden context');

    $this->get(route('projects.context.index', $project))
        ->assertSuccessful()
        ->assertSee('Project context')
        ->assertSee('Primary repository');
});

test('member cannot open the project context screen', function () {
    ['user' => $user, 'project' => $project] = projectContextScreenScene(TeamRole::Member);

    $this->actingAs($user);

    Livewire::test('pages::projects.context.index', ['project' => $project->id])
        ->assertStatus(403);
});

test('project page links admins to context screen', function () {
    ['user' => $user, 'project' => $project] = projectContextScreenScene();

    $this->actingAs($user);

    Livewire::test('pages::projects.show', ['project' => $project->id])
        ->assertSee(route('projects.context.index', $project), false)
        ->assertSee('Context');
});

test('project page hides context link from members', function () {
    ['user' => $user, 'project' => $project] = projectContextScreenScene(TeamRole::Member);

    $this->actingAs($user);

    Livewire::test('pages::projects.show', ['project' => $project->id])
        ->assertDontSee(route('projects.context.index', $project), false);
});
