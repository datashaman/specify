<?php

use App\Enums\TeamRole;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function featureShowScene(TeamRole $role = TeamRole::Admin): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create(['name' => 'Approval queue']);

    return compact('user', 'project', 'feature');
}

test('feature page lists its stories and links to a feature-prefilled create', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = featureShowScene();
    Story::factory()->for($feature)->create(['name' => 'Inline-listed-story']);

    $this->actingAs($user);

    Livewire::test('pages::features.show', ['project' => $project->id, 'feature' => $feature->id])
        ->assertSee('Approval queue')
        ->assertSee('Inline-listed-story');
});

test('new stories receive monotonic per-feature positions', function () {
    ['feature' => $feature] = featureShowScene();
    $a = Story::factory()->for($feature)->create();
    $b = Story::factory()->for($feature)->create();
    $c = Story::factory()->for($feature)->create();

    expect([$a->fresh()->position, $b->fresh()->position, $c->fresh()->position])->toBe([1, 2, 3]);
});

test('reorderStories rewrites positions and survives a refresh', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = featureShowScene();
    $a = Story::factory()->for($feature)->create(['name' => 'Alpha']);
    $b = Story::factory()->for($feature)->create(['name' => 'Bravo']);
    $c = Story::factory()->for($feature)->create(['name' => 'Charlie']);

    $this->actingAs($user);

    Livewire::test('pages::features.show', ['project' => $project->id, 'feature' => $feature->id])
        ->call('reorderStories', [$c->id, $a->id, $b->id]);

    expect($c->fresh()->position)->toBe(1);
    expect($a->fresh()->position)->toBe(2);
    expect($b->fresh()->position)->toBe(3);
});

test('reorderStories ignores foreign ids and noops on incomplete payload', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = featureShowScene();
    $a = Story::factory()->for($feature)->create();
    $b = Story::factory()->for($feature)->create();
    $c = Story::factory()->for($feature)->create();

    $otherFeature = Feature::factory()->for($project)->create();
    $foreign = Story::factory()->for($otherFeature)->create();

    $this->actingAs($user);

    Livewire::test('pages::features.show', ['project' => $project->id, 'feature' => $feature->id])
        ->call('reorderStories', [$b->id, $foreign->id, $a->id]);

    expect($a->fresh()->position)->toBe(1);
    expect($b->fresh()->position)->toBe(2);
    expect($c->fresh()->position)->toBe(3);
    expect($foreign->fresh()->position)->toBe(1);
});

test('Member cannot reorder stories', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = featureShowScene(TeamRole::Member);
    $a = Story::factory()->for($feature)->create();
    $b = Story::factory()->for($feature)->create();

    $this->actingAs($user);

    Livewire::test('pages::features.show', ['project' => $project->id, 'feature' => $feature->id])
        ->call('reorderStories', [$b->id, $a->id])
        ->assertStatus(403);
});

test('out-of-scope feature 404s', function () {
    ['user' => $user, 'project' => $project] = featureShowScene();

    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();

    $this->actingAs($user);

    Livewire::test('pages::features.show', ['project' => $project->id, 'feature' => $otherFeature->id])
        ->assertStatus(404);
});
