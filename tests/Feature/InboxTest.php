<?php

use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function inboxScene(): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 1,
    ]);

    return compact('workspace', 'team', 'user', 'project', 'feature');
}

test('inbox redirects unauthenticated users', function () {
    $this->get(route('inbox'))->assertRedirect(route('login'));
});

test('inbox lists pending stories and plans in scope', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene();

    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::PendingApproval,
        'name' => 'Visible story',
    ]);
    $plan = Plan::factory()->for($story)->create([
        'status' => PlanStatus::PendingApproval,
        'summary' => 'Visible plan',
    ]);
    Task::factory()->for($plan)->create(['name' => 'Visible task']);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->assertSee('Visible story')
        ->assertSee('Visible plan')
        ->assertSee('Visible task');
});

test('inbox excludes items from teams the user does not belong to', function () {
    ['user' => $user] = inboxScene();

    $otherWorkspace = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($otherWorkspace)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    Story::factory()->for($otherFeature)->create([
        'status' => StoryStatus::PendingApproval,
        'name' => 'Hidden story',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->assertDontSee('Hidden story');
});

test('approving a story flips its status', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::PendingApproval]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->call('decide', 'story', $story->id, 'approve');

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('approving a plan flips its status', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene();
    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Approved]);
    $plan = Plan::factory()->for($story)->create(['status' => PlanStatus::PendingApproval]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->call('decide', 'plan', $plan->id, 'approve');

    expect($plan->fresh()->status)->toBe(PlanStatus::Executing);
});

test('deciding on an out-of-scope item 404s', function () {
    ['user' => $user] = inboxScene();

    $otherWorkspace = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($otherWorkspace)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $otherStory = Story::factory()->for($otherFeature)->create([
        'status' => StoryStatus::PendingApproval,
    ]);

    $this->actingAs($user);

    expect(fn () => Livewire::test('pages::inbox')->call('decide', 'story', $otherStory->id, 'approve'))
        ->toThrow(ModelNotFoundException::class);
});
