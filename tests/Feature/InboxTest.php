<?php

use App\Enums\ApprovalDecision;
use App\Enums\PlanStatus;
use App\Enums\StoryStatus;
use App\Enums\TeamRole;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Plan;
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

test('Member sees only the no-permission notice (no buttons)', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene(TeamRole::Member);
    Story::factory()->for($feature)->create([
        'status' => StoryStatus::PendingApproval,
        'name' => 'Visible to member',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->assertSee('Visible to member')
        ->assertSee('Your role does not permit')
        ->assertDontSee('Approve');
});

test('inbox shows approval count and approver names', function () {
    ['user' => $user, 'feature' => $feature, 'project' => $project] = inboxScene();

    // Bump policy to require 2 approvals
    ApprovalPolicy::query()
        ->where('scope_type', ApprovalPolicy::SCOPE_PROJECT)
        ->where('scope_id', $project->id)
        ->update(['required_approvals' => 2]);

    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::PendingApproval]);
    $alice = User::factory()->create(['name' => 'Alice']);
    StoryApproval::create([
        'story_id' => $story->id,
        'story_revision' => $story->revision ?? 1,
        'approver_id' => $alice->id,
        'decision' => ApprovalDecision::Approve->value,
    ]);

    $this->actingAs($user);
    Livewire::test('pages::inbox')
        ->assertSee('1/2 approvals')
        ->assertSee('Alice');
});

test('Revoke replaces Approve button after the user has approved', function () {
    ['user' => $user, 'feature' => $feature, 'project' => $project] = inboxScene();
    ApprovalPolicy::query()
        ->where('scope_type', ApprovalPolicy::SCOPE_PROJECT)
        ->where('scope_id', $project->id)
        ->update(['required_approvals' => 2]);

    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::PendingApproval]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->call('decide', 'story', $story->id, 'approve')
        ->assertSee('Revoke approval');
});

test('Members can view the inbox but cannot approve', function () {
    ['user' => $user, 'feature' => $feature] = inboxScene(TeamRole::Member);
    $story = Story::factory()->for($feature)->create([
        'status' => StoryStatus::PendingApproval,
        'name' => 'Visible to member',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')->assertSee('Visible to member');

    Livewire::test('pages::inbox')
        ->call('decide', 'story', $story->id, 'approve')
        ->assertStatus(403);

    expect($story->fresh()->status)->toBe(StoryStatus::PendingApproval);
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
