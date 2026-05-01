<?php

use App\Enums\TeamRole;
use App\Models\ContextItem;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function projectContextItemStoreScene(TeamRole $role = TeamRole::Admin): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create(['name' => 'Specify']);

    return compact('user', 'project');
}

function contextItemStoreRequest($test): mixed
{
    return $test
        ->withSession(['_token' => 'context-token'])
        ->withHeader('X-CSRF-TOKEN', 'context-token');
}

test('guest cannot create a project context item', function () {
    $project = Project::factory()->create();

    contextItemStoreRequest($this)->postJson(route('projects.context-items.store', $project), [
        'type' => 'link',
        'title' => 'Design reference',
        'url' => 'https://example.com/design',
    ])->assertUnauthorized();
});

test('admin can create a link context item', function () {
    ['user' => $user, 'project' => $project] = projectContextItemStoreScene();

    contextItemStoreRequest($this)->actingAs($user)
        ->postJson(route('projects.context-items.store', $project), [
            'type' => 'link',
            'title' => 'Design reference',
            'description' => 'Source material for the UI.',
            'url' => 'https://example.com/design',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'link')
        ->assertJsonPath('data.title', 'Design reference')
        ->assertJsonPath('data.description', 'Source material for the UI.')
        ->assertJsonPath('data.metadata.url', 'https://example.com/design');

    $contextItem = ContextItem::query()->sole();

    expect($contextItem->project->is($project))->toBeTrue()
        ->and($contextItem->metadata)->toBe(['url' => 'https://example.com/design']);
});

test('admin can create a text context item', function () {
    ['user' => $user, 'project' => $project] = projectContextItemStoreScene();

    contextItemStoreRequest($this)->actingAs($user)
        ->postJson(route('projects.context-items.store', $project), [
            'type' => 'text',
            'title' => 'Domain notes',
            'body' => 'Projects collect context before subtasks are executed.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'text')
        ->assertJsonPath('data.metadata.body', 'Projects collect context before subtasks are executed.');

    expect(ContextItem::query()->sole()->metadata)
        ->toBe(['body' => 'Projects collect context before subtasks are executed.']);
});

test('admin can create a file context item', function () {
    Storage::fake('local');

    ['user' => $user, 'project' => $project] = projectContextItemStoreScene();
    $file = UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf');

    $response = contextItemStoreRequest($this)->actingAs($user)
        ->postJson(route('projects.context-items.store', $project), [
            'type' => 'file',
            'title' => 'Project brief',
            'file' => $file,
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'file')
        ->assertJsonPath('data.metadata.disk', 'local')
        ->assertJsonPath('data.metadata.original_name', 'brief.pdf')
        ->assertJsonPath('data.metadata.mime_type', 'application/pdf');

    $path = $response->json('data.metadata.path');

    expect($path)->toStartWith("context-items/{$project->id}/");
    Storage::disk('local')->assertExists($path);
});

test('admin cannot create a file context item larger than the configured limit', function () {
    Storage::fake('local');
    config()->set('specify.context_items.uploads.max_file_size_kilobytes', 1);
    config()->set('specify.context_items.uploads.allowed_extensions', ['txt']);

    ['user' => $user, 'project' => $project] = projectContextItemStoreScene();
    $file = UploadedFile::fake()->create('notes.txt', 2, 'text/plain');

    $response = contextItemStoreRequest($this)->actingAs($user)
        ->postJson(route('projects.context-items.store', $project), [
            'type' => 'file',
            'title' => 'Project notes',
            'file' => $file,
        ])
        ->assertInvalid([
            'file' => 'The context file must not be larger than 1 kilobytes.',
        ])
        ->assertJsonPath('error.code', 'context_item_upload_rejected')
        ->assertJsonPath('error.field', 'file')
        ->assertJsonPath('error.violations.0.rule', 'max_file_size')
        ->assertJsonPath('error.violations.0.limit.kilobytes', 1)
        ->assertJsonPath('error.violations.0.limit.bytes', 1024);

    expect($response->json('error.violations.0.actual.kilobytes'))->toBeGreaterThan(1);

    expect(ContextItem::query()->count())->toBe(0);
});

test('admin cannot create a file context item with a disallowed extension', function () {
    Storage::fake('local');
    config()->set('specify.context_items.uploads.allowed_extensions', ['txt']);

    ['user' => $user, 'project' => $project] = projectContextItemStoreScene();
    $file = UploadedFile::fake()->create('brief.pdf', 1, 'application/pdf');

    contextItemStoreRequest($this)->actingAs($user)
        ->postJson(route('projects.context-items.store', $project), [
            'type' => 'file',
            'title' => 'Project brief',
            'file' => $file,
        ])
        ->assertJsonPath('error.code', 'context_item_upload_rejected')
        ->assertJsonPath('error.field', 'file')
        ->assertJsonPath('error.violations.0.rule', 'allowed_file_type')
        ->assertJsonPath('error.violations.0.limit.allowed_extensions', ['txt'])
        ->assertJsonPath('error.violations.1.rule', 'allowed_extension')
        ->assertJsonPath('error.violations.1.limit.allowed_extensions', ['txt'])
        ->assertJsonPath('error.violations.1.actual.extension', 'pdf')
        ->assertInvalid([
            'file' => 'The context file must use one of these extensions: txt.',
        ]);

    expect(ContextItem::query()->count())->toBe(0);
});

test('admin cannot create a file context item with a disallowed content type', function () {
    Storage::fake('local');
    config()->set('specify.context_items.uploads.allowed_extensions', ['txt']);

    ['user' => $user, 'project' => $project] = projectContextItemStoreScene();
    $file = UploadedFile::fake()->create('notes.txt', 1, 'application/pdf');

    contextItemStoreRequest($this)->actingAs($user)
        ->postJson(route('projects.context-items.store', $project), [
            'type' => 'file',
            'title' => 'Project notes',
            'file' => $file,
        ])
        ->assertJsonPath('error.code', 'context_item_upload_rejected')
        ->assertJsonPath('error.field', 'file')
        ->assertJsonPath('error.violations.0.rule', 'allowed_file_type')
        ->assertJsonPath('error.violations.0.limit.allowed_extensions', ['txt'])
        ->assertJsonPath('error.violations.0.actual.mime_type', 'application/pdf')
        ->assertInvalid([
            'file' => 'The context file must use one of these file types: txt.',
        ]);

    expect(ContextItem::query()->count())->toBe(0);
});

test('team member cannot create a project context item', function () {
    ['user' => $user, 'project' => $project] = projectContextItemStoreScene(TeamRole::Member);

    contextItemStoreRequest($this)->actingAs($user)
        ->postJson(route('projects.context-items.store', $project), [
            'type' => 'link',
            'title' => 'Design reference',
            'url' => 'https://example.com/design',
        ])
        ->assertForbidden();

    expect(ContextItem::query()->count())->toBe(0);
});
