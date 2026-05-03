<?php

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Enums\TeamRole;
use App\Models\AcceptanceCriterion;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function inboxScene(TeamRole $role = TeamRole::Admin): array
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $user = User::factory()->create();
    $team->addMember($user, $role);
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

function pendingStoryFor(Feature $feature, string $name = 'Visible story'): Story
{
    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::PendingApproval,
        'name' => $name,
    ]);
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);

    return $story->fresh();
}

function pendingPlanFor(Feature $feature, string $name = 'Visible plan story')
{
    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::Approved,
        'name' => $name,
    ]);
    $task = Task::factory()->for($story)->create(['position' => 1]);
    $task->plan->forceFill(['status' => PlanStatus::PendingApproval->value])->save();

    return $task->plan->fresh();
}

test('inbox redirects unauthenticated users', function () {
    $this->get(route('triage'))->assertRedirect(route('login'));
});

test('inbox lists pending stories and current plans in scope', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene();

    pendingStoryFor($feature, 'Visible story');
    pendingPlanFor($feature, 'Visible plan');

    $this->actingAs($user);

    Livewire::test('pages::triage')
        ->assertSee('Visible story')
        ->assertSee('Visible plan')
        ->assertSee('Current plans pending approval');
});

test('inbox excludes items from teams the user does not belong to', function () {
    ['user' => $user] = inboxScene();

    $otherWorkspace = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($otherWorkspace)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    pendingStoryFor($otherFeature, 'Hidden story');

    $this->actingAs($user);

    Livewire::test('pages::triage')
        ->assertDontSee('Hidden story');
});

test('approving a story flips its status', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene();
    $story = pendingStoryFor($feature);

    $this->actingAs($user);

    Livewire::test('pages::triage')
        ->call('decide', $story->id, 'approve');

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
});

test('approving a current plan flips its status', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene();
    $plan = pendingPlanFor($feature);

    $this->actingAs($user);

    Livewire::test('pages::triage')
        ->call('decidePlan', $plan->id, 'approve');

    expect($plan->fresh()->status)->toBe(PlanStatus::Approved);
});

test('Member sees only the no-permission notice (no buttons)', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene(TeamRole::Member);
    pendingStoryFor($feature, 'Visible to member');

    $this->actingAs($user);

    Livewire::test('pages::triage')
        ->assertSee('Visible to member')
        ->assertSee('Your role does not permit')
        ->assertDontSee('Approve');
});

test('inbox shows approval count and approver names', function () {
    ['user' => $user, 'feature' => $feature, 'project' => $project] = inboxScene();

    ApprovalPolicy::query()
        ->where('scope_type', ApprovalPolicy::SCOPE_PROJECT)
        ->where('scope_id', $project->id)
        ->update(['required_approvals' => 2]);

    $story = pendingStoryFor($feature);
    $alice = User::factory()->create(['name' => 'Alice']);
    StoryApproval::create([
        'story_id' => $story->id,
        'story_revision' => $story->revision ?? 1,
        'approver_id' => $alice->id,
        'decision' => ApprovalDecision::Approve->value,
    ]);

    $this->actingAs($user);
    Livewire::test('pages::triage')
        ->assertSee('1/2 approvals')
        ->assertSee('Alice');
});

test('Revoke replaces Approve button after the user has approved', function () {
    ['user' => $user, 'feature' => $feature, 'project' => $project] = inboxScene();
    ApprovalPolicy::query()
        ->where('scope_type', ApprovalPolicy::SCOPE_PROJECT)
        ->where('scope_id', $project->id)
        ->update(['required_approvals' => 2]);

    $story = pendingStoryFor($feature);

    $this->actingAs($user);

    Livewire::test('pages::triage')
        ->call('decide', $story->id, 'approve')
        ->assertSee('Revoke approval');
});

test('Members can view the inbox but cannot approve', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene(TeamRole::Member);
    $story = pendingStoryFor($feature, 'Visible to member');

    $this->actingAs($user);

    Livewire::test('pages::triage')->assertSee('Visible to member');

    Livewire::test('pages::triage')
        ->call('decide', $story->id, 'approve')
        ->assertStatus(403);

    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);
});

test('pinning a project narrows the inbox to that project only', function () {
    ['user' => $user, 'project' => $projectA, 'feature' => $featureA] = inboxScene();
    $projectB = Project::factory()->for($projectA->team)->create(['name' => 'Project B']);
    $featureB = Feature::factory()->for($projectB)->create();
    pendingStoryFor($featureA, 'in-A');
    pendingStoryFor($featureB, 'in-B');

    $user->switchProject($projectA);
    $this->actingAs($user->fresh());

    Livewire::test('pages::triage')
        ->assertSee('in-A')
        ->assertDontSee('in-B');
});

test('deciding on an out-of-scope plan 404s', function () {
    ['user' => $user] = inboxScene();

    $otherWorkspace = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($otherWorkspace)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $otherPlan = pendingPlanFor($otherFeature);

    $this->actingAs($user);

    expect(fn () => Livewire::test('pages::triage')->call('decidePlan', $otherPlan->id, 'approve'))
        ->toThrow(ModelNotFoundException::class);
});

test('deciding on an out-of-scope item 404s', function () {
    ['user' => $user] = inboxScene();

    $otherWorkspace = Workspace::factory()->create();
    $otherTeam = Team::factory()->for($otherWorkspace)->create();
    $otherProject = Project::factory()->for($otherTeam)->create();
    $otherFeature = Feature::factory()->for($otherProject)->create();
    $otherStory = pendingStoryFor($otherFeature);

    $this->actingAs($user);

    expect(fn () => Livewire::test('pages::triage')->call('decide', $otherStory->id, 'approve'))
        ->toThrow(ModelNotFoundException::class);
});
