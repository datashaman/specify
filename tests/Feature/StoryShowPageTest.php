<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalDecision;
use App\Enums\StoryStatus;
use App\Enums\TeamRole;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\ContextItem;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function showPageScene(array $opts = []): array
{
    $ws = Workspace::factory()->create();
    $team = Team::factory()->for($ws)->create();
    $user = User::factory()->create();
    $team->addMember($user, TeamRole::Owner);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create([
        'name' => $opts['story_name'] ?? 'show-page-story',
        'status' => $opts['status'] ?? StoryStatus::Draft,
        'revision' => $opts['revision'] ?? 1,
        'created_by_id' => $opts['author_id'] ?? $user->id,
    ]);

    return compact('ws', 'team', 'user', 'project', 'feature', 'story');
}

function attachPolicy(Workspace $ws, int $required, bool $allowSelf = false): ApprovalPolicy
{
    return ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_WORKSPACE,
        'scope_id' => $ws->id,
        'required_approvals' => $required,
        'allow_self_approval' => $allowSelf,
        'auto_approve' => false,
    ]);
}

/**
 * Force a Story into a target status without firing the revision-bump /
 * ApprovalService::recompute chain. Use after creating ACs in tests, since
 * AcceptanceCriterion::saved bumps revision and would otherwise revert
 * Approved → PendingApproval when a threshold isn't met by approvals.
 */
function forceStoryStatus(Story $story, StoryStatus $status, ?int $revision = null): void
{
    Story::withoutEvents(function () use ($story, $status, $revision) {
        $story->forceFill(array_filter([
            'status' => $status->value,
            'revision' => $revision,
        ]))->save();
    });
}

test('approved story renders rail=approved and tally pill 2/2', function () {
    $s = showPageScene(['status' => StoryStatus::Approved]);
    attachPolicy($s['ws'], required: 2);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-rail="approved"')
        ->assertSeeHtml('data-pill="approved"')
        ->assertSee('2/2');
});

test('pending story renders rail=pending and tally 1/2 reflects current approvals', function () {
    $other = User::factory()->create();
    $s = showPageScene(['status' => StoryStatus::PendingApproval, 'author_id' => $other->id]);
    $s['team']->addMember($other, TeamRole::Member);
    attachPolicy($s['ws'], required: 2);

    StoryApproval::create([
        'story_id' => $s['story']->id,
        'approver_id' => $other->id,
        'decision' => ApprovalDecision::Approve,
        'story_revision' => 1,
    ]);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-rail="pending"')
        ->assertSeeHtml('data-pill="pending"')
        ->assertSee('1/2');
});

test('changes-requested story renders rail and pill without tally', function () {
    $s = showPageScene(['status' => StoryStatus::ChangesRequested]);
    attachPolicy($s['ws'], required: 2);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-rail="changes_requested"')
        ->assertSeeHtml('data-pill="changes_requested"')
        ->assertSee('Changes requested')
        ->assertDontSee('1/2')
        ->assertDontSee('2/2');
});

test('decision log right rail renders one row per StoryApproval for current revision', function () {
    $alice = User::factory()->create(['name' => 'alice']);
    $bob = User::factory()->create(['name' => 'bob']);
    $s = showPageScene(['status' => StoryStatus::Approved]);
    $s['team']->addMember($alice, TeamRole::Member);
    $s['team']->addMember($bob, TeamRole::Member);
    attachPolicy($s['ws'], required: 2);

    StoryApproval::create([
        'story_id' => $s['story']->id,
        'approver_id' => $alice->id,
        'decision' => ApprovalDecision::Approve,
        'story_revision' => 1,
    ]);
    StoryApproval::create([
        'story_id' => $s['story']->id,
        'approver_id' => $bob->id,
        'decision' => ApprovalDecision::Approve,
        'story_revision' => 1,
    ]);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-section="decision-log"')
        ->assertSeeInOrder(['alice', 'bob']);
});

test('author cannot approve own pending story when allow_self_approval=false', function () {
    $s = showPageScene(['status' => StoryStatus::PendingApproval]);
    attachPolicy($s['ws'], required: 1, allowSelf: false);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertDontSee('Approve')
        ->assertSee('disallows self-approval');
});

test('author can approve own pending story when allow_self_approval=true', function () {
    $s = showPageScene(['status' => StoryStatus::PendingApproval]);
    attachPolicy($s['ws'], required: 1, allowSelf: true);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSee('Approve');
});

test('eligible-approvers section renders only when threshold > 1', function () {
    $other = User::factory()->create(['name' => 'second-eligible']);
    $s = showPageScene(['status' => StoryStatus::PendingApproval]);
    $s['team']->addMember($other, TeamRole::Admin);
    attachPolicy($s['ws'], required: 2);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-section="eligible-approvers"')
        ->assertSee('second-eligible');
});

test('eligible-approvers section hidden when threshold = 1', function () {
    $s = showPageScene(['status' => StoryStatus::PendingApproval]);
    attachPolicy($s['ws'], required: 1);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertDontSeeHtml('data-section="eligible-approvers"');
});

test('plan section is AC-led: AC text leads, Task name follows', function () {
    $s = showPageScene(['status' => StoryStatus::Approved]);
    attachPolicy($s['ws'], required: 1);

    $ac = AcceptanceCriterion::create([
        'story_id' => $s['story']->id,
        'position' => 1,
        'criterion' => 'AC-text-must-lead',
    ]);
    Task::factory()->for($s['story'])->create([
        'name' => 'task-name-secondary',
        'position' => 1,
        'acceptance_criterion_id' => $ac->id,
    ]);

    $this->actingAs($s['user']);

    $html = Livewire::test('pages::stories.show', ['story' => $s['story']->id])->html();
    $acIdx = strpos($html, 'AC-text-must-lead');
    $taskIdx = strpos($html, 'task-name-secondary');

    expect($acIdx)->not->toBeFalse()
        ->and($taskIdx)->not->toBeFalse()
        ->and($acIdx)->toBeLessThan($taskIdx);
});

test('task missing acceptance_criterion_id renders under Unmapped tasks', function () {
    $s = showPageScene(['status' => StoryStatus::Approved]);
    attachPolicy($s['ws'], required: 1);

    Task::factory()->for($s['story'])->create([
        'name' => 'orphan-task',
        'position' => 1,
        'acceptance_criterion_id' => null,
    ]);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-ac="unmapped"')
        ->assertSee('orphan-task');
});

test('appended subtask renders provenance glyph and tooltip referencing originating run id', function () {
    $s = showPageScene(['status' => StoryStatus::Approved]);
    attachPolicy($s['ws'], required: 1);

    $ac = AcceptanceCriterion::create([
        'story_id' => $s['story']->id, 'position' => 1, 'criterion' => 'ac',
    ]);
    $task = Task::factory()->for($s['story'])->create([
        'position' => 1,
        'acceptance_criterion_id' => $ac->id,
    ]);
    $sourceSub = Subtask::factory()->for($task)->create(['name' => 'source-sub', 'position' => 1]);
    $run = AgentRun::factory()->create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $sourceSub->id,
        'status' => AgentRunStatus::Succeeded,
    ]);
    Subtask::factory()->for($task)->create([
        'name' => 'appended-sub',
        'position' => 2,
        'proposed_by_run_id' => $run->id,
    ]);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-provenance')
        ->assertSeeHtml('Appended by Run #'.$run->id);
});

test('reset-approval banner renders with delta when AC count changes during edit on Approved story', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);

    $original = AcceptanceCriterion::create([
        'story_id' => $s['story']->id, 'position' => 1, 'criterion' => 'original-ac',
    ]);
    forceStoryStatus($s['story'], StoryStatus::Approved);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->call('startEdit')
        ->set('editCriteria', [
            ['id' => $original->id, 'criterion' => 'original-ac'],
            ['id' => null, 'criterion' => 'newly-added-ac'],
        ])
        ->assertSeeHtml('data-banner="reset-approval"')
        ->assertSee('+1')
        ->assertSee('Save & request re-approval');
});

test('reset-approval banner renders ~1 when an existing AC text is edited on Approved story', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);

    $original = AcceptanceCriterion::create([
        'story_id' => $s['story']->id, 'position' => 1, 'criterion' => 'original-ac',
    ]);
    forceStoryStatus($s['story'], StoryStatus::Approved);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->call('startEdit')
        ->set('editCriteria', [
            ['id' => $original->id, 'criterion' => 'edited-ac-text'],
        ])
        ->assertSeeHtml('data-banner="reset-approval"')
        ->assertSee('~1')
        ->assertSee('Save & request re-approval');
});

test('reset-approval banner does not render when nothing changes during edit', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);

    AcceptanceCriterion::create([
        'story_id' => $s['story']->id, 'position' => 1, 'criterion' => 'unchanged-ac',
    ]);
    forceStoryStatus($s['story'], StoryStatus::Approved);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->call('startEdit')
        ->assertDontSeeHtml('data-banner="reset-approval"')
        ->assertSee('Save')
        ->assertDontSee('Save & request re-approval');
});

test('plan section renders with compact/expanded toggle controls', function () {
    $s = showPageScene(['status' => StoryStatus::Approved]);
    attachPolicy($s['ws'], required: 1);
    AcceptanceCriterion::create([
        'story_id' => $s['story']->id, 'position' => 1, 'criterion' => 'has-an-ac',
    ]);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-toggle="plan-mode"')
        ->assertSee('Compact')
        ->assertSee('Expanded');
});

test('plan toggle is hidden when story has no ACs and no unmapped tasks', function () {
    $s = showPageScene(['status' => StoryStatus::Approved]);
    attachPolicy($s['ws'], required: 1);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertDontSeeHtml('data-toggle="plan-mode"');
});

test('story page lists attached context items and excludes them from the attach picker', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);
    $attached = ContextItem::factory()->for($s['project'])->create([
        'type' => 'document',
        'title' => 'Attached architecture note',
        'description' => 'Short implementation context for the executor.',
    ]);
    $available = ContextItem::factory()->for($s['project'])->create(['title' => 'Available interview']);
    $s['story']->contextItems()->attach($attached);

    $this->actingAs($s['user']);

    $html = Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSeeHtml('data-section="story-context-items"')
        ->assertSee('Attached architecture note')
        ->assertSee('Short implementation context for the executor.')
        ->assertSee('document')
        ->assertSee('Available interview')
        ->html();

    expect(substr_count($html, 'Attached architecture note'))->toBe(1)
        ->and($html)->not->toContain('available-context-'.$attached->id)
        ->and($html)->toContain('available-context-'.$available->id);
});

test('story attach picker only lists context items from the story project', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);
    $sameProject = ContextItem::factory()->for($s['project'])->create(['title' => 'Same project brief']);
    $otherProject = Project::factory()->for($s['team'])->create();
    $otherProjectItem = ContextItem::factory()->for($otherProject)->create(['title' => 'Other project brief']);

    $this->actingAs($s['user']);

    $html = Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSee('Same project brief')
        ->assertDontSee('Other project brief')
        ->html();

    expect($html)->toContain('available-context-'.$sameProject->id)
        ->and($html)->not->toContain('available-context-'.$otherProjectItem->id);
});

test('author can attach multiple available project context items from story page', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);
    $first = ContextItem::factory()->for($s['project'])->create(['title' => 'Repository map']);
    $second = ContextItem::factory()->for($s['project'])->create(['title' => 'Product brief']);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->set('selectedContextItemIds', [$first->id, $second->id])
        ->call('attachContextItems')
        ->assertSet('selectedContextItemIds', [])
        ->assertSee('Repository map')
        ->assertSee('Product brief')
        ->assertDontSeeHtml('available-context-'.$first->id)
        ->assertDontSeeHtml('available-context-'.$second->id);

    expect($s['story']->fresh()->contextItems()->orderBy('context_items.id')->pluck('context_items.id')->all())
        ->toBe([$first->id, $second->id]);
});

test('author can detach a context item from the story page without a refresh', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);
    $detached = ContextItem::factory()->for($s['project'])->create(['title' => 'Outdated note']);
    $kept = ContextItem::factory()->for($s['project'])->create(['title' => 'Current note']);
    $s['story']->contextItems()->attach([$detached->id, $kept->id]);

    $this->actingAs($s['user']);

    $component = Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSee('Outdated note')
        ->assertSee('Current note')
        ->assertSeeHtml('wire:click="detachContextItem('.$detached->id.')"')
        ->call('detachContextItem', $detached->id)
        ->assertSee('Current note')
        ->assertDontSeeHtml('attached-context-'.$detached->id)
        ->assertSeeHtml('available-context-'.$detached->id);

    expect(substr_count($component->html(), 'Outdated note'))->toBe(1);

    expect($s['story']->fresh()->contextItems()->orderBy('context_items.id')->pluck('context_items.id')->all())
        ->toBe([$kept->id]);
});

test('Draft story exposes a Delete button that removes the story and redirects to the feature', function () {
    $s = showPageScene(['status' => StoryStatus::Draft]);
    attachPolicy($s['ws'], required: 1);

    $this->actingAs($s['user']);

    $component = Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertSee('Delete')
        ->call('deleteStory');

    $component->assertRedirect(route('features.show', [
        'project' => $s['project']->id,
        'feature' => $s['feature']->id,
    ]));

    expect(Story::find($s['story']->id))->toBeNull();
});

test('Approved story does not expose a Delete button and deleteStory is forbidden', function () {
    $s = showPageScene(['status' => StoryStatus::Approved]);
    attachPolicy($s['ws'], required: 1);

    $this->actingAs($s['user']);

    Livewire::test('pages::stories.show', ['story' => $s['story']->id])
        ->assertDontSeeHtml('wire:click="deleteStory"')
        ->call('deleteStory')
        ->assertForbidden();

    expect(Story::find($s['story']->id))->not->toBeNull();
});
