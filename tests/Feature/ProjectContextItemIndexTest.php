<?php

use App\Enums\TeamRole;
use App\Models\ContextItem;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;

function projectContextItemScene(TeamRole $role = TeamRole::Member): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);

    return compact('user', 'project');
}

test('guest cannot list project context items', function () {
    $project = Project::factory()->create();

    $this->getJson(route('projects.context-items.index', $project))
        ->assertUnauthorized();
});

test('team member can list project context items', function () {
    ['user' => $user, 'project' => $project] = projectContextItemScene();
    $contextItem = ContextItem::factory()->for($project)->create([
        'type' => 'repository',
        'title' => 'Primary repository',
        'description' => 'The repository to use for implementation work.',
        'metadata' => [
            'provider' => 'github',
            'default_branch' => 'main',
        ],
    ]);

    ContextItem::factory()->create(['title' => 'Hidden context']);

    $this->actingAs($user)
        ->getJson(route('projects.context-items.index', $project))
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $contextItem->id)
        ->assertJsonPath('data.0.type', 'repository')
        ->assertJsonPath('data.0.title', 'Primary repository')
        ->assertJsonPath('data.0.description', 'The repository to use for implementation work.')
        ->assertJsonPath('data.0.metadata.provider', 'github')
        ->assertJsonPath('data.0.metadata.default_branch', 'main')
        ->assertJsonPath('meta.can_manage_project', false)
        ->assertJsonCount(1, 'data');
});

test('team admin context item list response exposes manage permission', function () {
    ['user' => $user, 'project' => $project] = projectContextItemScene(TeamRole::Admin);

    $this->actingAs($user)
        ->getJson(route('projects.context-items.index', $project))
        ->assertSuccessful()
        ->assertJsonPath('meta.can_manage_project', true);
});

test('non member cannot list project context items', function () {
    ['project' => $project] = projectContextItemScene();
    $user = User::factory()->create();
    ContextItem::factory()->for($project)->create();

    $this->actingAs($user)
        ->getJson(route('projects.context-items.index', $project))
        ->assertForbidden();
});
