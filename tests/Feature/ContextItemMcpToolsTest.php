<?php

use App\Enums\ContextItemType;
use App\Enums\StoryStatus;
use App\Enums\TeamRole;
use App\Mcp\Tools\AddProjectAssetTool;
use App\Mcp\Tools\AddStoryAssetTool;
use App\Mcp\Tools\DeleteContextItemTool;
use App\Mcp\Tools\ListContextItemsTool;
use App\Mcp\Tools\UpdateContextItemTool;
use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Context\ContextItemWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

function contextMcpScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Admin);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved, 'revision' => 1]);

    return compact('user', 'project', 'story');
}

// ── list-context-items ──────────────────────────────────────────────────────

test('list-context-items returns project assets', function () {
    ['user' => $user, 'project' => $project] = contextMcpScene();
    $item = ContextItem::factory()->for($project)->forText('body')->create(['title' => 'Spec']);
    $this->actingAs($user);

    $response = app(ListContextItemsTool::class)->handle(new Request([
        'project_id' => $project->id,
    ]));

    expect($response->isError())->toBeFalse();
    $data = json_decode((string) $response->content(), true);
    expect(collect($data)->firstWhere('id', $item->id)['title'])->toBe('Spec');
});

test('list-context-items returns story assets only', function () {
    ['user' => $user, 'project' => $project, 'story' => $story] = contextMcpScene();
    ContextItem::factory()->for($project)->for($story)->forText('body')->create(['title' => 'Story note']);
    ContextItem::factory()->for($project)->forText('body')->create(['title' => 'Project note']);
    $this->actingAs($user);

    $response = app(ListContextItemsTool::class)->handle(new Request([
        'story_id' => $story->id,
    ]));

    $data = json_decode((string) $response->content(), true);
    expect(collect($data)->pluck('title')->all())->toBe(['Story note']);
});

test('list-context-items rejects both ids at once', function () {
    ['user' => $user, 'project' => $project, 'story' => $story] = contextMcpScene();
    $this->actingAs($user);

    $response = app(ListContextItemsTool::class)->handle(new Request([
        'project_id' => $project->id,
        'story_id' => $story->id,
    ]));

    expect($response->isError())->toBeTrue();
});

// ── add-project-asset ───────────────────────────────────────────────────────

test('add-project-asset creates text item', function () {
    ['user' => $user, 'project' => $project] = contextMcpScene();
    $this->actingAs($user);

    $response = app(AddProjectAssetTool::class)->handle(new Request([
        'project_id' => $project->id,
        'type' => 'text',
        'title' => 'Style guide',
        'body' => 'Use Oxford commas.',
    ]), app(ContextItemWriter::class));

    expect($response->isError())->toBeFalse();
    $item = $project->contextItems()->first();
    expect($item->type)->toBe(ContextItemType::Text);
    expect($item->story_id)->toBeNull();
});

test('add-project-asset creates link item', function () {
    ['user' => $user, 'project' => $project] = contextMcpScene();
    $this->actingAs($user);

    $response = app(AddProjectAssetTool::class)->handle(new Request([
        'project_id' => $project->id,
        'type' => 'link',
        'title' => 'Figma',
        'url' => 'https://figma.com/x',
    ]), app(ContextItemWriter::class));

    expect($response->isError())->toBeFalse();
    $item = $project->contextItems()->first();
    expect($item->type)->toBe(ContextItemType::Link);
    expect($item->metadata['url'])->toBe('https://figma.com/x');
});

test('add-project-asset requires body for text type', function () {
    ['user' => $user, 'project' => $project] = contextMcpScene();
    $this->actingAs($user);

    $response = app(AddProjectAssetTool::class)->handle(new Request([
        'project_id' => $project->id,
        'type' => 'text',
        'title' => 'Incomplete',
    ]), app(ContextItemWriter::class));

    expect($response->isError())->toBeTrue();
});

// ── add-story-asset ─────────────────────────────────────────────────────────

test('add-story-asset auto-includes and bumps revision', function () {
    ['user' => $user, 'story' => $story] = contextMcpScene();
    $before = $story->fresh()->revision;
    $this->actingAs($user);

    $response = app(AddStoryAssetTool::class)->handle(new Request([
        'story_id' => $story->id,
        'type' => 'text',
        'title' => 'Story note',
        'body' => 'Important detail.',
    ]), app(ContextItemWriter::class));

    expect($response->isError())->toBeFalse();
    $item = $story->ownedContextItems()->first();
    expect($story->fresh()->revision)->toBe($before + 1);
    expect($story->includedContextItems()->whereKey($item->id)->exists())->toBeTrue();
});

test('add-story-asset requires url for link type', function () {
    ['user' => $user, 'story' => $story] = contextMcpScene();
    $this->actingAs($user);

    $response = app(AddStoryAssetTool::class)->handle(new Request([
        'story_id' => $story->id,
        'type' => 'link',
        'title' => 'Missing URL',
    ]), app(ContextItemWriter::class));

    expect($response->isError())->toBeTrue();
});

// ── update-context-item ─────────────────────────────────────────────────────

test('update-context-item updates title and body for text', function () {
    ['user' => $user, 'project' => $project] = contextMcpScene();
    $item = ContextItem::factory()->for($project)->forText('old body')->create(['title' => 'Old']);
    $this->actingAs($user);

    $response = app(UpdateContextItemTool::class)->handle(new Request([
        'id' => $item->id,
        'title' => 'New',
        'body' => 'new body',
    ]), app(ContextItemWriter::class));

    expect($response->isError())->toBeFalse();
    expect($item->fresh()->title)->toBe('New');
    expect($item->fresh()->metadata['body'])->toBe('new body');
});

test('update-context-item updates url for link', function () {
    ['user' => $user, 'project' => $project] = contextMcpScene();
    $item = ContextItem::factory()->for($project)->forLink('https://old.example.com')->create(['title' => 'Link']);
    $this->actingAs($user);

    $response = app(UpdateContextItemTool::class)->handle(new Request([
        'id' => $item->id,
        'url' => 'https://new.example.com',
    ]), app(ContextItemWriter::class));

    expect($response->isError())->toBeFalse();
    expect($item->fresh()->metadata['url'])->toBe('https://new.example.com');
});

// ── delete-context-item ─────────────────────────────────────────────────────

test('delete-context-item removes the row', function () {
    ['user' => $user, 'project' => $project] = contextMcpScene();
    $item = ContextItem::factory()->for($project)->forText('x')->create();
    $this->actingAs($user);

    $response = app(DeleteContextItemTool::class)->handle(
        new Request(['id' => $item->id]),
        app(ContextItemWriter::class),
    );

    expect($response->isError())->toBeFalse();
    expect(ContextItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

test('delete-context-item on story asset bumps revision', function () {
    ['user' => $user, 'project' => $project, 'story' => $story] = contextMcpScene();
    $item = ContextItem::factory()->for($project)->for($story)->forText('x')->create();
    $before = $story->fresh()->revision;
    $this->actingAs($user);

    app(DeleteContextItemTool::class)->handle(
        new Request(['id' => $item->id]),
        app(ContextItemWriter::class),
    );

    expect($story->fresh()->revision)->toBe($before + 1);
});

test('delete-context-item returns error for inaccessible item', function () {
    ['user' => $user] = contextMcpScene();
    $other = Project::factory()->for(Team::factory()->for(Workspace::factory()->create())->create())->create();
    $item = ContextItem::factory()->for($other)->forText('x')->create();
    $this->actingAs($user);

    $response = app(DeleteContextItemTool::class)->handle(
        new Request(['id' => $item->id]),
        app(ContextItemWriter::class),
    );

    expect($response->isError())->toBeTrue();
});
