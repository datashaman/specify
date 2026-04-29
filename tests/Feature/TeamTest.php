<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('team belongs to workspace', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();

    expect($team->workspace->id)->toBe($workspace->id)
        ->and($workspace->teams)->toHaveCount(1);
});

test('team can add members with roles', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $team->addMember($owner, TeamRole::Owner);
    $team->addMember($member);

    expect($team->members)->toHaveCount(2)
        ->and($team->roleFor($owner))->toBe(TeamRole::Owner)
        ->and($team->roleFor($member))->toBe(TeamRole::Member);
});

test('user can belong to multiple teams', function () {
    $user = User::factory()->create();
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $teamA->addMember($user, TeamRole::Admin);
    $teamB->addMember($user);

    expect($user->teams)->toHaveCount(2);
});

test('team slug is unique within workspace but free across workspaces', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    Team::factory()->for($a)->create(['slug' => 'core']);
    Team::factory()->for($b)->create(['slug' => 'core']);

    expect(fn () => Team::factory()->for($a)->create(['slug' => 'core']))
        ->toThrow(Exception::class);
});

test('user can switch to a team they belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->addMember($user);

    $user->switchTeam($team);

    expect($user->fresh()->currentTeam->id)->toBe($team->id);
});

test('user cannot switch to a team they do not belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    expect(fn () => $user->switchTeam($team))
        ->toThrow(InvalidArgumentException::class, 'not a member');
});

test('deleting current team nulls the pointer', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->addMember($user);
    $user->switchTeam($team);

    $team->delete();

    expect($user->fresh()->current_team_id)->toBeNull();
});

test('removing user from current team nulls the pointer', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->addMember($user);
    $user->switchTeam($team);

    $team->removeMember($user);

    expect($user->fresh()->current_team_id)->toBeNull();
});

test('deleting workspace cascades to teams and pivot', function () {
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);

    $workspace->delete();

    expect(Team::find($team->id))->toBeNull()
        ->and($user->fresh()->teams()->count())->toBe(0);
});
