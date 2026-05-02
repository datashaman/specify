<?php

use App\Enums\TeamRole;
use App\Models\ContextItem;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;

function projectContextItemMutationScene(TeamRole $role = TeamRole::Admin): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);
    $contextItem = ContextItem::factory()->for($project)->create([
        'title' => 'Original title',
        'description' => 'Original description',
        'metadata' => ['url' => 'https://example.com/original'],
    ]);

    return compact('user', 'project', 'contextItem');
}

function contextItemMutationRequest($test): mixed
{
    return $test
        ->withSession(['_token' => 'context-token'])
        ->withHeader('X-CSRF-TOKEN', 'context-token');
}

test('guest cannot update a project context item', function () {
    ['project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene();

    contextItemMutationRequest($this)
        ->patchJson(route('projects.context-items.update', [$project, $contextItem]), [
            'title' => 'Updated title',
        ])
        ->assertUnauthorized();
});

test('admin can update a project context item title and description', function () {
    ['user' => $user, 'project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene();

    contextItemMutationRequest($this)->actingAs($user)
        ->patchJson(route('projects.context-items.update', [$project, $contextItem]), [
            'title' => 'Updated title',
            'description' => 'Updated description',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $contextItem->id)
        ->assertJsonPath('data.title', 'Updated title')
        ->assertJsonPath('data.description', 'Updated description')
        ->assertJsonPath('data.metadata.url', 'https://example.com/original');

    expect($contextItem->refresh())
        ->title->toBe('Updated title')
        ->description->toBe('Updated description');
});

test('owner can update a project context item', function () {
    ['user' => $user, 'project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene(TeamRole::Owner);

    contextItemMutationRequest($this)->actingAs($user)
        ->patchJson(route('projects.context-items.update', [$project, $contextItem]), [
            'title' => 'Owner updated title',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.title', 'Owner updated title');

    expect($contextItem->refresh()->title)->toBe('Owner updated title');
});

test('admin can clear a project context item description', function () {
    ['user' => $user, 'project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene();

    contextItemMutationRequest($this)->actingAs($user)
        ->patchJson(route('projects.context-items.update', [$project, $contextItem]), [
            'description' => null,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.description', null);

    expect($contextItem->refresh()->description)->toBeNull();
});

test('team member cannot update a project context item', function () {
    ['user' => $user, 'project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene(TeamRole::Member);

    contextItemMutationRequest($this)->actingAs($user)
        ->patchJson(route('projects.context-items.update', [$project, $contextItem]), [
            'title' => 'Updated title',
        ])
        ->assertForbidden();

    expect($contextItem->refresh()->title)->toBe('Original title');
});

test('admin cannot update a context item from another project', function () {
    ['user' => $user, 'project' => $project] = projectContextItemMutationScene();
    $otherContextItem = ContextItem::factory()->create();

    contextItemMutationRequest($this)->actingAs($user)
        ->patchJson(route('projects.context-items.update', [$project, $otherContextItem]), [
            'title' => 'Updated title',
        ])
        ->assertNotFound();

    expect($otherContextItem->refresh()->title)->not->toBe('Updated title');
});

test('updating a missing project context item returns not found', function () {
    ['user' => $user, 'project' => $project] = projectContextItemMutationScene();

    contextItemMutationRequest($this)->actingAs($user)
        ->patchJson(route('projects.context-items.update', [$project, 999999]), [
            'title' => 'Updated title',
        ])
        ->assertNotFound();
});

test('guest cannot delete a project context item', function () {
    ['project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene();

    contextItemMutationRequest($this)
        ->deleteJson(route('projects.context-items.destroy', [$project, $contextItem]))
        ->assertUnauthorized();
});

test('admin can delete a project context item', function () {
    ['user' => $user, 'project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene();

    contextItemMutationRequest($this)->actingAs($user)
        ->deleteJson(route('projects.context-items.destroy', [$project, $contextItem]))
        ->assertNoContent();

    $this->assertModelMissing($contextItem);
});

test('owner can delete a project context item', function () {
    ['user' => $user, 'project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene(TeamRole::Owner);

    contextItemMutationRequest($this)->actingAs($user)
        ->deleteJson(route('projects.context-items.destroy', [$project, $contextItem]))
        ->assertNoContent();

    $this->assertModelMissing($contextItem);
});

test('team member cannot delete a project context item', function () {
    ['user' => $user, 'project' => $project, 'contextItem' => $contextItem] = projectContextItemMutationScene(TeamRole::Member);

    contextItemMutationRequest($this)->actingAs($user)
        ->deleteJson(route('projects.context-items.destroy', [$project, $contextItem]))
        ->assertForbidden();

    $this->assertModelExists($contextItem);
});

test('admin cannot delete a context item from another project', function () {
    ['user' => $user, 'project' => $project] = projectContextItemMutationScene();
    $otherContextItem = ContextItem::factory()->create();

    contextItemMutationRequest($this)->actingAs($user)
        ->deleteJson(route('projects.context-items.destroy', [$project, $otherContextItem]))
        ->assertNotFound();

    $this->assertModelExists($otherContextItem);
});

test('deleting a missing project context item returns not found', function () {
    ['user' => $user, 'project' => $project] = projectContextItemMutationScene();

    contextItemMutationRequest($this)->actingAs($user)
        ->deleteJson(route('projects.context-items.destroy', [$project, 999999]))
        ->assertNotFound();
});
