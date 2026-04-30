<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Bash;
use App\Ai\Tools\EditFile;
use App\Ai\Tools\Find;
use App\Ai\Tools\Grep;
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
            return [];
        }

        $sandbox = new Sandbox($this->workingDir);

        return [
            new ReadFile($sandbox),
            new WriteFile($sandbox),
            new EditFile($sandbox),
            new Bash($sandbox),
            new Grep($sandbox),
            new Find($sandbox),
            new Ls($sandbox),
        ];
    }

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are the execution agent for Specify. A human has approved a Story and its
task list; your job is to execute one Subtask against the working copy of the
repository that has already been checked out for you on the working branch.

You have these tools — use them to inspect and modify the working tree:

- read(path, offset?, limit?)
- write(path, content)
- edit(path, edits[]) — each edit has `old_string`, `new_string`, optional `replace_all`
- bash(command, timeout?) — runs in the working directory
- grep(pattern, path?, glob?, ignore_case?, literal?, context?, limit?)
- find(pattern, path?, limit?)
- ls(path?, limit?)

Workflow:
1. Use `ls`, `find`, `grep`, `read` to orient yourself in the repo.
2. Use `edit` for surgical changes, `write` for whole-file replacements.
3. Use `bash` to run tests, formatters, or build steps. Do not commit, push,
   open PRs, or switch branches — those are handled by the orchestrator.
4. When the Subtask is satisfied, return your structured summary.

Constraints:
- Make only the changes the Subtask requires. Do not refactor unrelated code.
- If the Subtask is ambiguous, prefer the smallest interpretation that satisfies it.
- Paths in tool calls are relative to the working directory (the repo root).

Return a structured summary of what was done. List each file you touched
(use the same paths you passed to write/edit). Provide a one-line commit
message in conventional-commit form (e.g. "feat: add CSV export endpoint").
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
        ];
    }
}
