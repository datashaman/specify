<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Bash;
use App\Ai\Tools\EditFile;
use App\Ai\Tools\Find;
use App\Ai\Tools\Grep;
use App\Ai\Tools\LoggedTool;
use App\Ai\Tools\Ls;
use App\Ai\Tools\ReadFile;
use App\Ai\Tools\Sandbox;
use App\Ai\Tools\WriteFile;
use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[UseSmartestModel]
#[MaxSteps(40)]
#[MaxTokens(4096)]
class SubtaskExecutor implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        public Subtask $subtask,
        public ?Repo $repo = null,
        public ?string $workingBranch = null,
        public ?string $workingDir = null,
    ) {}

    public function tools(): iterable
    {
        if ($this->workingDir === null) {
            throw new \RuntimeException(
                'SubtaskExecutor::tools() called without a working directory. '
                .'The executor that constructed this agent must set workingDir; '
                .'otherwise the model has no way to inspect or mutate the repo.'
            );
        }

        $sandbox = new Sandbox($this->workingDir);
        $context = [
            'subtask_id' => $this->subtask->getKey(),
            'story_id' => $this->subtask->task?->story_id,
            'branch' => $this->workingBranch,
        ];

        return collect([
            new ReadFile($sandbox),
            new WriteFile($sandbox),
            new EditFile($sandbox),
            new Bash($sandbox),
            new Grep($sandbox),
            new Find($sandbox),
            new Ls($sandbox),
        ])->map(fn ($tool) => new LoggedTool($tool, $context))->all();
    }

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are the execution agent for Specify. A human has approved a Story and its
task list; your job is to execute one Subtask against the working copy of the
repository that has already been checked out for you on the working branch.

You have these tools — use them to inspect and modify the working tree:

- ReadFile(path, offset?, limit?)
- WriteFile(path, content)
- EditFile(path, edits[]) — each edit has `old_string`, `new_string`, optional `replace_all`
- Bash(command, timeout?) — runs in the working directory
- Grep(pattern, path?, glob?, ignore_case?, literal?, context?, limit?)
- Find(pattern, path?, limit?)
- Ls(path?, limit?)

You MUST use these tools to do the work. Reading a file with ReadFile and
then writing a modified version is the basic pattern; for surgical changes
prefer EditFile. Do not just describe what should happen — actually run the
tools. When you are satisfied that the working tree contains the changes
the Subtask requires, call output_structured_data with your summary.

Workflow:
1. Use Ls, Find, Grep, ReadFile to orient yourself in the repo.
2. Use EditFile for surgical changes, WriteFile for whole-file replacements.
3. Use Bash to run tests, formatters, or build steps. Do not commit, push,
   open PRs, or switch branches — those are handled by the orchestrator.
4. When the Subtask is satisfied, return your structured summary.

Constraints:
- Make only the changes the Subtask requires. Do not refactor unrelated code.
- Paths in tool calls are relative to the working directory (the repo root).

You are a collaborator, not a worker. Two voice channels are available in the
structured output and you should use them deliberately:

- `clarifications` — if the Subtask is ambiguous, conflicts with another part
  of the Story, you found missing context, or you would have chosen
  differently than what was specified, **execute the smallest reasonable
  interpretation AND record a clarification**. Each clarification has a
  `kind` (one of `ambiguity`, `conflict`, `missing-context`, `disagreement`),
  a `message`, and an optional `proposed` describing what you think should
  change. The human reviewer sees these alongside the diff. Do not invent
  clarifications to look thoughtful — only record real signal.
- `proposed_subtasks` — if completing this Subtask reveals additional work
  needed to finish the parent Task (not the whole Story; just the Task),
  propose follow-up Subtasks. Each entry has `name`, `description`, and
  `reason`. They are appended to the parent Task and execute after this
  Subtask succeeds. Cap: at most three proposed Subtasks per run; surplus is
  discarded. Use this when you discover required work, not as a backlog
  dumping ground.

Return a structured summary of what was done. List each file you touched
(use the same paths you passed to write/edit). Provide a one-line commit
message in conventional-commit form (e.g. "feat: add CSV export endpoint").
Leave `clarifications` and `proposed_subtasks` empty when there is nothing
real to report.
INSTRUCTIONS;
    }

    public function buildPrompt(): string
    {
        $subtask = $this->subtask->loadMissing('task.story.feature.project', 'task.acceptanceCriterion');
        $task = $subtask->task;
        $story = $task?->story;
        $criterion = $task?->acceptanceCriterion?->criterion;

        $repoBlock = $this->repo
            ? "Repository: {$this->repo->name}\nURL: {$this->repo->url}\nDefault branch: {$this->repo->default_branch}\nWorking branch: {$this->workingBranch}"
            : 'Repository: (none specified — operate on context only)';

        $criterionBlock = $criterion ? "Acceptance Criterion: {$criterion}\n\n" : '';
        $taskBlock = $task ? "Parent Task #{$task->position}: {$task->name}\n" : '';

        return <<<PROMPT
Story: {$story?->name}

Description:
{$story?->description}

{$criterionBlock}{$taskBlock}Subtask #{$subtask->position}: {$subtask->name}

Subtask description:
{$subtask->description}

{$repoBlock}

Execute this Subtask and return a summary.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'files_changed' => $schema->array()
                ->items($schema->string())
                ->required(),
            'commit_message' => $schema->string()->required(),
            'clarifications' => $schema->array()
                ->items(
                    $schema->object(fn ($schema) => [
                        'kind' => $schema->string()
                            ->enum(['ambiguity', 'conflict', 'missing-context', 'disagreement'])
                            ->required(),
                        'message' => $schema->string()->required(),
                        'proposed' => $schema->string(),
                    ])
                ),
            'proposed_subtasks' => $schema->array()
                ->items(
                    $schema->object(fn ($schema) => [
                        'name' => $schema->string()->required(),
                        'description' => $schema->string()->required(),
                        'reason' => $schema->string()->required(),
                    ])
                ),
        ];
    }
}
