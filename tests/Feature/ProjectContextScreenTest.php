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
        'metadata' => ['branch' => 'main', 'visibility' => 'private'],
    ]);
    ContextItem::factory()->create(['title' => 'Hidden context']);

    $this->actingAs($user);

    Livewire::test('pages::projects.context.index', ['project' => $project->id])
        ->assertSee('Project context')
        ->assertSee('Specify')
        ->assertSee('context-items')
        ->assertSee('Loading context items')
        ->assertSee('Unable to load context items')
        ->assertSee('No context items yet.')
        ->assertSee('contextItem.title', false)
        ->assertSee('contextItem.description', false)
        ->assertSee('metadataEntries(contextItem.metadata)', false)
        ->assertDontSee('Hidden context');

    $this->get(route('projects.context.index', $project))
        ->assertSuccessful()
        ->assertSee('Project context')
        ->assertSee('context-items');

    $this->getJson(route('projects.context-items.index', $project))
        ->assertSuccessful()
        ->assertJsonPath('data.0.type', 'repository')
        ->assertJsonPath('data.0.title', 'Primary repository')
        ->assertJsonPath('data.0.description', 'The implementation repository.')
        ->assertJsonPath('data.0.metadata.branch', 'main')
        ->assertJsonPath('data.0.metadata.visibility', 'private');
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
