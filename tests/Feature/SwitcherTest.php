<?php

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function switcherUserAcross(int $workspaces): array
{
    $user = User::factory()->create();
    $teams = [];
    $projects = [];
    for ($i = 0; $i < $workspaces; $i++) {
        $ws = Workspace::factory()->create(['name' => "WS-$i"]);
        $team = Team::factory()->for($ws)->create();
        $team->addMember($user);
        $teams[] = $team;
        $projects[$i] = [
            Project::factory()->for($team)->create(['name' => "WS-$i-A"]),
            Project::factory()->for($team)->create(['name' => "WS-$i-B"]),
        ];
    }
    $user->forceFill(['current_team_id' => $teams[0]->id])->save();

    return [$user, $teams, $projects];
}

test('switchWorkspace flips current_team_id and clears current_project_id', function () {
    [$user, $teams, $projects] = switcherUserAcross(2);
    $user->switchProject($projects[0][0]);
    expect($user->fresh()->current_project_id)->toBe($projects[0][0]->id);

    $user->switchWorkspace($teams[1]->workspace);

    $fresh = $user->fresh();
    expect($fresh->current_team_id)->toBe($teams[1]->id)
        ->and($fresh->current_project_id)->toBeNull();
});

test('switchWorkspace rejects a workspace the user has no team in', function () {
    [$user] = switcherUserAcross(1);
    $other = Workspace::factory()->create();
    Team::factory()->for($other)->create();

    expect(fn () => $user->switchWorkspace($other))
        ->toThrow(InvalidArgumentException::class);
});

test('scopedProjectIds returns the current_project_id alone when set', function () {
    [$user, , $projects] = switcherUserAcross(1);
    $user->switchProject($projects[0][1]);

    expect($user->fresh()->scopedProjectIds())
        ->toBe([$projects[0][1]->id]);
});

test('scopedProjectIds defaults to current-workspace projects when no project is pinned', function () {
    [$user, , $projects] = switcherUserAcross(2);

    $ids = $user->fresh()->scopedProjectIds();
    expect($ids)->toContain($projects[0][0]->id)
        ->and($ids)->toContain($projects[0][1]->id)
        ->and($ids)->not->toContain($projects[1][0]->id);
});

test('switchProject rejects a project outside the user accessible teams', function () {
    [$user] = switcherUserAcross(1);
    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();

    expect(fn () => $user->switchProject($otherProject))
        ->toThrow(InvalidArgumentException::class);
});

test('app-switcher component switches workspace via UI action', function () {
    [$user, $teams] = switcherUserAcross(2);

    $this->actingAs($user);

    Livewire::test('app-switcher')
        ->call('switchWorkspace', $teams[1]->workspace_id);

    expect($user->fresh()->current_team_id)->toBe($teams[1]->id);
});

test('Admin can create a project from the switcher', function () {
    [$user] = switcherUserAcross(1);
    // Promote to Admin so canCreateProject is true.
    $user->teams()->updateExistingPivot($user->current_team_id, ['role' => 'admin']);

    $this->actingAs($user);

    Livewire::test('app-switcher')
        ->set('newProjectName', 'Brand new')
        ->set('newProjectDescription', 'desc')
        ->call('createProject');

    $created = Project::where('name', 'Brand new')->firstOrFail();
    expect($created->team_id)->toBe($user->current_team_id)
        ->and($user->fresh()->current_project_id)->toBe($created->id);
});

test('Member cannot create a project from the switcher', function () {
    [$user] = switcherUserAcross(1);

    $this->actingAs($user);

    Livewire::test('app-switcher')
        ->set('newProjectName', 'Sneaky')
        ->call('createProject')
        ->assertStatus(403);

    expect(Project::where('name', 'Sneaky')->exists())->toBeFalse();
});

test('app-switcher component sets and clears the project pin', function () {
    [$user, , $projects] = switcherUserAcross(1);

    $this->actingAs($user);

    Livewire::test('app-switcher')
        ->call('switchProject', $projects[0][0]->id);
    expect($user->fresh()->current_project_id)->toBe($projects[0][0]->id);

    Livewire::test('app-switcher')
        ->call('switchProject', null);
    expect($user->fresh()->current_project_id)->toBeNull();
});
