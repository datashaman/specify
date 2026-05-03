<?php

use App\Ai\Agents\SubtaskExecutor;
use App\Enums\StoryStatus;
use App\Models\AcceptanceCriterion;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// WorkspaceRunner / CliExecutor tests cd into temp directories and tear
// them down. If a later test inherits a deleted cwd, every git/process
// invocation fails with `getcwd: cannot access parent directories`. Reset
// the cwd to the project root before every test so the suite is order-
// independent regardless of what a prior test did.
beforeEach(function () {
    chdir(base_path());
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function makeStory(): Story
{
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();

    $story = Story::factory()->for($feature)->create(['status' => StoryStatus::Draft]);
    AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);

    return $story->fresh();
}

function approvedStoryInProjectWithRepo(): Story
{
    config(['queue.default' => 'sync']);
    SubtaskExecutor::fake(fn () => [
        'summary' => 'noop',
        'files_changed' => [],
        'commit_message' => 'noop',
    ]);

    $story = Story::factory()->create();
    AcceptanceCriterion::factory()->for($story)->create([
        'position' => 1,
        'statement' => 'Demo acceptance criterion.',
    ]);

    $project = $story->feature->project;
    $workspace = $project->team->workspace;
    $repo = Repo::factory()->for($workspace)->create();
    $project->attachRepo($repo);

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 0,
    ]);

    $ac = $story->acceptanceCriteria()->first();
    $task = Task::factory()->create([
        'story_id' => $story->id,
        'acceptance_criterion_id' => $ac?->id,
        'position' => 1,
    ]);
    Subtask::factory()->for($task)->create(['position' => 1, 'name' => 'only-sub']);

    $story->forceFill(['status' => StoryStatus::Draft->value])->save();
    $story->fresh()->submitForApproval();

    $plan = Plan::query()->find($task->plan_id);
    if ($plan) {
        $plan->submitForApproval();
    }

    return $story->fresh();
}
