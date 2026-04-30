<?php

use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function authorScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    return compact('user', 'project', 'feature');
}

test('save draft creates a Story in Draft with acceptance criteria', function () {
    ['user' => $user, 'feature' => $feature] = authorScene();

    $this->actingAs($user);

    Livewire::test('pages::stories.create')
        ->set('feature_id', $feature->id)
        ->set('name', 'Authored from UI')
        ->set('description', 'Reviewers can author new stories.')
        ->set('criteria', ['Page renders', 'Save persists'])
        ->call('save', false);

    $story = Story::where('name', 'Authored from UI')->firstOrFail();
    expect($story->status)->toBe(StoryStatus::Draft)
        ->and($story->feature_id)->toBe($feature->id)
        ->and($story->created_by_id)->toBe($user->id)
        ->and(AcceptanceCriterion::where('story_id', $story->id)->count())->toBe(2);
});

test('save & submit transitions the story for approval', function () {
    ['user' => $user, 'project' => $project, 'feature' => $feature] = authorScene();
    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 1,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::stories.create')
        ->set('feature_id', $feature->id)
        ->set('name', 'Submit me')
        ->set('description', 'desc')
        ->set('criteria', ['Renders'])
        ->call('save', true);

    $story = Story::where('name', 'Submit me')->firstOrFail();
    expect($story->status)->toBe(StoryStatus::PendingApproval);
});

test('cannot save against a feature outside the user\'s teams', function () {
    ['user' => $user] = authorScene();
    $other = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($other)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();

    $this->actingAs($user);

    expect(fn () => Livewire::test('pages::stories.create')
        ->set('feature_id', $otherFeature->id)
        ->set('name', 'Sneak')
        ->set('description', 'no')
        ->call('save', false))
        ->toThrow(ModelNotFoundException::class);
});

test('unauthenticated visitors are redirected', function () {
    $this->get(route('stories.create'))->assertRedirect(route('login'));
});
