<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('workspace requires an owner', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    expect($workspace->owner->id)->toBe($owner->id);
});

test('project belongs to a team and exposes its workspace through the team', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();

    expect($project->team->id)->toBe($team->id)
        ->and($project->workspace->id)->toBe($workspace->id);
});

test('project supports a creator user for audit', function () {
    $creator = User::factory()->create();
    $project = Project::factory()->create(['created_by_id' => $creator->id]);

    expect($project->creator->id)->toBe($creator->id);
});

test('story supports a creator user for audit', function () {
    $creator = User::factory()->create();
    $story = Story::factory()->create(['created_by_id' => $creator->id]);

    expect($story->creator->id)->toBe($creator->id);
});

test('registration auto-provisions a workspace, team, and current team', function () {
    $user = (new CreateNewUser)->create([
        'name' => 'Marlin',
        'email' => 'marlin@example.com',
        'password' => 'Password!2345',
        'password_confirmation' => 'Password!2345',
    ]);

    $workspace = Workspace::where('owner_id', $user->id)->firstOrFail();
    $team = $workspace->teams()->firstOrFail();

    expect($workspace->name)->toContain('Marlin')
        ->and($team->slug)->toBe('default')
        ->and($team->roleFor($user))->toBe(TeamRole::Owner)
        ->and($user->fresh()->currentTeam->id)->toBe($team->id);
});

test('deleting an owning user is blocked while they own a workspace', function () {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create();

    expect(fn () => $user->delete())
        ->toThrow(Exception::class);
});
