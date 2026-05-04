<?php

use App\Enums\AgentRunStatus;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Context\ContextBuilder;
use App\Services\Context\NullContextBuilder;
use App\Services\Executors\CliExecutor;
use App\Services\Executors\ExecutionResult;
use App\Services\Executors\Executor;
use App\Services\Executors\ExecutorClarification;
use App\Services\Executors\ProposedSubtask;
use App\Services\PlanWriter;
use App\Services\Progress\ProgressEmitter;
use App\Services\PullRequests\PrPayloadBuilder;
use App\Services\SubtaskRunOutcome;
use App\Services\SubtaskRunPipeline;
use App\Services\WorkspaceRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeApprovedTask(): Task
{
    $story = Story::factory()->create(['status' => StoryStatus::Approved, 'revision' => 1]);
    $ac = AcceptanceCriterion::factory()->for($story)->create(['position' => 1]);

    return Task::factory()->forCurrentPlanOf($story)->create([
        'name' => 'wire CSV export',
        'position' => 1,
        'acceptance_criterion_id' => $ac->id,
    ]);
}

test('PlanWriter::appendProposedSubtasks appends without resetting Story approval (ADR-0005 carve-out)', function () {
    $task = makeApprovedTask();
    Subtask::factory()->for($task)->create(['position' => 1, 'name' => 'first', 'status' => TaskStatus::Done]);
    $story = $task->plan->story;

    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $task->subtasks()->first()->getKey(),
        'status' => 'succeeded',
    ]);

    expect($story->fresh()->status)->toBe(StoryStatus::Approved);
    $originalRevision = $story->fresh()->revision;

    $proposed = [
        new ProposedSubtask('add tests', 'add Pest test for export controller', 'no test coverage on the new endpoint'),
    ];

    $created = app(PlanWriter::class)->appendProposedSubtasks($task, $proposed, $run);

    expect($created)->toHaveCount(1)
        ->and($created[0]->position)->toBe(2)
        ->and($created[0]->proposed_by_run_id)->toBe($run->getKey())
        ->and($created[0]->description)->toContain('_Reason:_ no test coverage')
        ->and($story->fresh()->status)->toBe(StoryStatus::Approved)
        ->and($story->fresh()->revision)->toBe($originalRevision);
});

test('PlanWriter::appendProposedSubtasks caps at 3 entries with a warning', function () {
    $task = makeApprovedTask();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => 1,
        'status' => 'succeeded',
    ]);

    $proposed = array_map(
        fn ($i) => new ProposedSubtask("step $i", "do step $i", "needed because $i"),
        range(1, 5),
    );

    $created = app(PlanWriter::class)->appendProposedSubtasks($task, $proposed, $run);

    expect($created)->toHaveCount(3);
});

test('PlanWriter::appendProposedSubtasks numbers new positions after the highest existing', function () {
    $task = makeApprovedTask();
    Subtask::factory()->for($task)->create(['position' => 7]);
    Subtask::factory()->for($task)->create(['position' => 12]);

    $run = AgentRun::create(['runnable_type' => Subtask::class, 'runnable_id' => 1, 'status' => 'succeeded']);

    $created = app(PlanWriter::class)->appendProposedSubtasks(
        $task,
        [new ProposedSubtask('next', 'do next', 'because')],
        $run,
    );

    expect($created[0]->position)->toBe(13);
});

test('ExecutorClarification ignores entries with unknown kind or empty message', function () {
    expect(ExecutorClarification::fromArray(['kind' => 'invalid', 'message' => 'x']))->toBeNull()
        ->and(ExecutorClarification::fromArray(['kind' => 'ambiguity', 'message' => '']))->toBeNull()
        ->and(ExecutorClarification::fromArray(['kind' => 'ambiguity', 'message' => 'real']))
        ->toBeInstanceOf(ExecutorClarification::class);
});

test('ProposedSubtask requires name, description, and reason', function () {
    expect(ProposedSubtask::fromArray(['name' => 'x', 'description' => 'y']))->toBeNull()
        ->and(ProposedSubtask::fromArray(['name' => '', 'description' => 'y', 'reason' => 'z']))->toBeNull()
        ->and(ProposedSubtask::fromArray(['name' => 'x', 'description' => 'y', 'reason' => 'z']))
        ->toBeInstanceOf(ProposedSubtask::class);
});

test('PR body renders the Proposed follow-up subtasks section when the pipeline appended any', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'main work']);

    $body = PrPayloadBuilder::body($subtask, [
        'summary' => 'done',
        'appended_subtasks' => [
            ['id' => 99, 'position' => 2, 'name' => 'add tests'],
            ['id' => 100, 'position' => 3, 'name' => 'document endpoint'],
        ],
    ]);

    expect($body)
        ->toContain('## Proposed follow-up subtasks')
        ->toContain('automatically (ADR-0005)')
        ->toContain('#2 — add tests')
        ->toContain('#3 — document endpoint');
});

test('SubtaskRunPipeline does NOT append proposed subtasks when the run ends in noDiff', function () {
    // Regression: an executor proposes follow-ups, but the run produces no
    // commit (noDiff). The appended subtasks must not be persisted, or they
    // would be orphaned under a Task whose run was marked Failed.
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'main', 'position' => 1]);
    $repo = Repo::factory()->for($task->plan->story->feature->project->team->workspace)->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'working_branch' => 'specify/test',
        'status' => AgentRunStatus::Running,
    ]);

    $executor = new class implements Executor
    {
        public function needsWorkingDirectory(): bool
        {
            return true;
        }

        public function supportsProgressEvents(): bool
        {
            return false;
        }

        public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
        {
            return new ExecutionResult(
                summary: 'I tried but produced no diff',
                filesChanged: [],
                commitMessage: 'noop',
                proposedSubtasks: [
                    new ProposedSubtask('would-be-followup', 'should not exist', 'never run is the test'),
                ],
            );
        }
    };

    $workspace = new class extends WorkspaceRunner
    {
        public function __construct() {}

        public function prepare(Repo $repo, AgentRun $run): string
        {
            return sys_get_temp_dir();
        }

        public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void {}

        public function commit(string $workingDir, string $message): ?string
        {
            return null;
        }

        public function diff(string $workingDir, ?string $base = null): string
        {
            return '';
        }
    };

    $pipeline = new SubtaskRunPipeline($executor, $workspace, app(PlanWriter::class), new NullContextBuilder);

    $beforeCount = $task->subtasks()->count();
    $outcome = $pipeline->run($run);
    $afterCount = $task->fresh()->subtasks()->count();

    expect($outcome->state)->toBe(SubtaskRunOutcome::STATE_NO_DIFF)
        ->and($afterCount)->toBe($beforeCount);
});

test('alreadyComplete returns the Succeeded-class outcome when evidence SHAs are reachable from HEAD (ADR-0007)', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'already-done', 'position' => 1]);
    $repo = Repo::factory()->for($task->plan->story->feature->project->team->workspace)->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'working_branch' => 'specify/test',
        'status' => AgentRunStatus::Running,
    ]);

    $executor = new class implements Executor
    {
        public function needsWorkingDirectory(): bool
        {
            return true;
        }

        public function supportsProgressEvents(): bool
        {
            return false;
        }

        public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
        {
            return new ExecutionResult(
                summary: 'Confirmed already done by abc1234',
                filesChanged: [],
                commitMessage: 'noop',
                alreadyComplete: true,
                alreadyCompleteEvidence: ['abc1234', 'def5678'],
            );
        }
    };

    $workspace = new class extends WorkspaceRunner
    {
        public function __construct() {}

        public function prepare(Repo $repo, AgentRun $run): string
        {
            return sys_get_temp_dir();
        }

        public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void {}

        public function commit(string $workingDir, string $message): ?string
        {
            return null;
        }

        public function diff(string $workingDir, ?string $base = null): string
        {
            return '';
        }

        public function isCommitReachableFromHead(string $workingDir, string $sha): bool
        {
            return in_array($sha, ['abc1234', 'def5678'], true);
        }
    };

    $pipeline = new SubtaskRunPipeline($executor, $workspace, app(PlanWriter::class), new NullContextBuilder);
    $outcome = $pipeline->run($run);

    expect($outcome->state)->toBe(SubtaskRunOutcome::STATE_ALREADY_COMPLETE)
        ->and($outcome->isSucceeded())->toBeTrue()
        ->and($outcome->output['already_complete'] ?? null)->toBeTrue()
        ->and($outcome->output['already_complete_evidence'] ?? [])->toBe(['abc1234', 'def5678'])
        ->and($outcome->output['already_complete_reason'] ?? null)->toContain('Confirmed');
});

test('alreadyComplete falls through to noDiff when evidence is empty (ADR-0007 safety net)', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'sus-claim', 'position' => 1]);
    $repo = Repo::factory()->for($task->plan->story->feature->project->team->workspace)->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'working_branch' => 'specify/test',
        'status' => AgentRunStatus::Running,
    ]);

    $executor = new class implements Executor
    {
        public function needsWorkingDirectory(): bool
        {
            return true;
        }

        public function supportsProgressEvents(): bool
        {
            return false;
        }

        public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
        {
            return new ExecutionResult(
                summary: 'I swear it is already done',
                filesChanged: [],
                commitMessage: 'noop',
                alreadyComplete: true,
                alreadyCompleteEvidence: [],
            );
        }
    };

    $workspace = new class extends WorkspaceRunner
    {
        public function __construct() {}

        public function prepare(Repo $repo, AgentRun $run): string
        {
            return sys_get_temp_dir();
        }

        public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void {}

        public function commit(string $workingDir, string $message): ?string
        {
            return null;
        }

        public function diff(string $workingDir, ?string $base = null): string
        {
            return '';
        }

        public function isCommitReachableFromHead(string $workingDir, string $sha): bool
        {
            return false;
        }
    };

    $pipeline = new SubtaskRunPipeline($executor, $workspace, app(PlanWriter::class), new NullContextBuilder);
    $outcome = $pipeline->run($run);

    expect($outcome->state)->toBe(SubtaskRunOutcome::STATE_NO_DIFF);
});

test('alreadyComplete falls through to noDiff when ANY cited SHA is unreachable, even if others verify (ADR-0007 all-or-nothing)', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'partial-claim', 'position' => 1]);
    $repo = Repo::factory()->for($task->plan->story->feature->project->team->workspace)->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'working_branch' => 'specify/test',
        'status' => AgentRunStatus::Running,
    ]);

    $executor = new class implements Executor
    {
        public function needsWorkingDirectory(): bool
        {
            return true;
        }

        public function supportsProgressEvents(): bool
        {
            return false;
        }

        public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
        {
            return new ExecutionResult(
                summary: 'One real, one made up',
                filesChanged: [],
                commitMessage: 'noop',
                alreadyComplete: true,
                alreadyCompleteEvidence: ['abc1234', 'deadbeef'],
            );
        }
    };

    $workspace = new class extends WorkspaceRunner
    {
        public function __construct() {}

        public function prepare(Repo $repo, AgentRun $run): string
        {
            return sys_get_temp_dir();
        }

        public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void {}

        public function commit(string $workingDir, string $message): ?string
        {
            return null;
        }

        public function diff(string $workingDir, ?string $base = null): string
        {
            return '';
        }

        public function isCommitReachableFromHead(string $workingDir, string $sha): bool
        {
            return $sha === 'abc1234';
        }
    };

    $pipeline = new SubtaskRunPipeline($executor, $workspace, app(PlanWriter::class), new NullContextBuilder);
    $outcome = $pipeline->run($run);

    expect($outcome->state)->toBe(SubtaskRunOutcome::STATE_NO_DIFF)
        ->and($outcome->error)->toContain('already_complete')
        ->and($outcome->error)->toContain('not all reachable');
});

test('alreadyComplete falls through to noDiff when none of the cited SHAs are reachable (ADR-0007 safety net)', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'hallucinated', 'position' => 1]);
    $repo = Repo::factory()->for($task->plan->story->feature->project->team->workspace)->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'working_branch' => 'specify/test',
        'status' => AgentRunStatus::Running,
    ]);

    $executor = new class implements Executor
    {
        public function needsWorkingDirectory(): bool
        {
            return true;
        }

        public function supportsProgressEvents(): bool
        {
            return false;
        }

        public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
        {
            return new ExecutionResult(
                summary: 'Done in commit deadbeef',
                filesChanged: [],
                commitMessage: 'noop',
                alreadyComplete: true,
                alreadyCompleteEvidence: ['deadbeef'],
            );
        }
    };

    $workspace = new class extends WorkspaceRunner
    {
        public function __construct() {}

        public function prepare(Repo $repo, AgentRun $run): string
        {
            return sys_get_temp_dir();
        }

        public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void {}

        public function commit(string $workingDir, string $message): ?string
        {
            return null;
        }

        public function diff(string $workingDir, ?string $base = null): string
        {
            return '';
        }

        public function isCommitReachableFromHead(string $workingDir, string $sha): bool
        {
            return false;
        }
    };

    $pipeline = new SubtaskRunPipeline($executor, $workspace, app(PlanWriter::class), new NullContextBuilder);
    $outcome = $pipeline->run($run);

    expect($outcome->state)->toBe(SubtaskRunOutcome::STATE_NO_DIFF);
});

test('CliExecutor parses the already_complete sentinel out of stdout', function () {
    $stdout = "doing some work\nlooks fine\n<<<SPECIFY:already_complete>>>abc1234, def5678\n9012345<<<END>>>\nbye\n";
    $reflection = new ReflectionMethod(CliExecutor::class, 'parseAlreadyCompleteSentinel');
    $reflection->setAccessible(true);

    $exec = new CliExecutor(['true']);
    [$flag, $shas] = $reflection->invoke($exec, $stdout);

    expect($flag)->toBeTrue()
        ->and($shas)->toBe(['abc1234', 'def5678', '9012345']);
});

test('CliExecutor returns false/empty when the sentinel is absent', function () {
    $reflection = new ReflectionMethod(CliExecutor::class, 'parseAlreadyCompleteSentinel');
    $reflection->setAccessible(true);

    $exec = new CliExecutor(['true']);
    [$flag, $shas] = $reflection->invoke($exec, "ordinary agent output\nno sentinel here\n");

    expect($flag)->toBeFalse()
        ->and($shas)->toBe([]);
});

test('context_brief is persisted on AgentRun.output even when the run ends in noDiff', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'main', 'position' => 1]);
    $repo = Repo::factory()->for($task->plan->story->feature->project->team->workspace)->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'working_branch' => 'specify/test',
        'status' => AgentRunStatus::Running,
    ]);

    $executor = new class implements Executor
    {
        public function needsWorkingDirectory(): bool
        {
            return true;
        }

        public function supportsProgressEvents(): bool
        {
            return false;
        }

        public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
        {
            return new ExecutionResult(summary: '', filesChanged: [], commitMessage: 'noop');
        }
    };

    $workspace = new class extends WorkspaceRunner
    {
        public function __construct() {}

        public function prepare(Repo $repo, AgentRun $run): string
        {
            return sys_get_temp_dir();
        }

        public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void {}

        public function commit(string $workingDir, string $message): ?string
        {
            return null;
        }

        public function diff(string $workingDir, ?string $base = null): string
        {
            return '';
        }
    };

    $contextBuilder = new class implements ContextBuilder
    {
        public function build(Subtask $subtask, ?string $workingDir, ?Repo $repo): string
        {
            return "<context-brief>\n\nSEEN BY AGENT\n\n</context-brief>";
        }
    };

    $pipeline = new SubtaskRunPipeline($executor, $workspace, app(PlanWriter::class), $contextBuilder);
    $outcome = $pipeline->run($run);

    expect($outcome->state)->toBe(SubtaskRunOutcome::STATE_NO_DIFF);

    // Even though the pipeline returns noDiff (which the job will mark
    // Failed without persisting outcome.output), the brief must already be
    // on the AgentRun row from the early save so debugging is possible.
    $persisted = $run->fresh()->output;
    expect($persisted)->toBeArray()
        ->and($persisted['context_brief'] ?? null)->toContain('SEEN BY AGENT');
});

test('PR body renders clarifications with their proposed alternative when present', function () {
    $task = makeApprovedTask();
    $subtask = Subtask::factory()->for($task)->create(['name' => 'main']);

    $body = PrPayloadBuilder::body($subtask, [
        'summary' => 'done',
        'clarifications' => [
            [
                'kind' => 'disagreement',
                'message' => 'Subtask says use synchronous IO; the rest of the codebase uses queued jobs.',
                'proposed' => 'Switch to a queued job for consistency.',
            ],
        ],
    ]);

    expect($body)
        ->toContain('[disagreement]')
        ->toContain('synchronous IO')
        ->toContain('_Proposed:_ Switch to a queued job');
});
