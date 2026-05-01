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
use App\Services\Prompts\PromptLoader;
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
        return app(PromptLoader::class)->load('subtask-executor');
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
            'already_complete' => $schema->boolean(),
            'already_complete_evidence' => $schema->array()->items($schema->string()),
        ];
    }
}
