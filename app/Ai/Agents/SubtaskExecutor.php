<?php

namespace App\Ai\Agents;

use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[UseSmartestModel]
#[MaxTokens(4096)]
#[Temperature(0.2)]
class SubtaskExecutor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Subtask $subtask,
        public ?Repo $repo = null,
        public ?string $workingBranch = null,
    ) {}

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are the execution agent for Specify. A human has approved a Story and its
task list; your job is to execute one Subtask against the specified repository
on the specified working branch.

Constraints:
- Make only the changes the Subtask requires. Do not refactor unrelated code.
- Stay on the working branch provided. Do not switch branches or rebase.
- Never push, open PRs, or merge — your scope ends at producing a clean diff.
- If the Subtask is ambiguous, prefer the smallest interpretation that satisfies it.

Return a structured summary of what was done so the orchestration system can
record the run. List each file you touched. Provide a one-line commit message
in conventional-commit form (e.g. "feat: add CSV export endpoint").
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
