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
use RuntimeException;

/**
 * Sibling of `SubtaskExecutor` for the review-response loop (ADR-0008).
 *
 * Same tool box, different prompt + structured output. Where SubtaskExecutor
 * implements a Subtask spec from scratch, ReviewResponder is given the
 * already-existing branch state plus the open review comments and is asked
 * to push a focused `fix(review):` change for each comment (or push back
 * via `clarifications`).
 */
#[Provider(Lab::Anthropic)]
#[UseSmartestModel]
#[MaxSteps(40)]
#[MaxTokens(4096)]
class ReviewResponder implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * @param  list<array{path: ?string, line: ?int, body: string, author: ?string}>  $comments
     */
    public function __construct(
        public Subtask $subtask,
        public int $pullRequestNumber,
        public string $reviewSummary,
        public array $comments,
        public ?string $workingBranch = null,
        public ?string $workingDir = null,
    ) {}

    public function tools(): iterable
    {
        if ($this->workingDir === null) {
            throw new RuntimeException(
                'ReviewResponder::tools() called without a working directory. '
                .'The job that constructed this agent must set workingDir.'
            );
        }

        $sandbox = new Sandbox($this->workingDir);
        $context = [
            'subtask_id' => $this->subtask->getKey(),
            'pull_request_number' => $this->pullRequestNumber,
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
        return app(PromptLoader::class)->load('review-responder');
    }

    public function buildPrompt(): string
    {
        $subtask = $this->subtask->loadMissing('task.plan.story.feature.project', 'task.acceptanceCriterion');
        $task = $subtask->task;
        $story = $task?->plan?->story;
        $criterion = $task?->acceptanceCriterion?->statement;

        $criterionBlock = $criterion ? "Acceptance Criterion: {$criterion}\n\n" : '';
        $taskBlock = $task ? "Parent Task #{$task->position}: {$task->name}\n" : '';

        $reviewBlock = trim($this->reviewSummary) === ''
            ? "Review summary: (none)\n"
            : "Review summary:\n{$this->reviewSummary}\n";

        $commentBlocks = [];
        foreach ($this->comments as $i => $c) {
            $where = $c['path'] !== null
                ? "{$c['path']}".($c['line'] !== null ? ":{$c['line']}" : '')
                : '(general comment)';
            $author = $c['author'] !== null && $c['author'] !== '' ? " by @{$c['author']}" : '';
            $commentBlocks[] = 'Comment '.($i + 1)." — {$where}{$author}:\n{$c['body']}";
        }
        $commentCount = count($this->comments);
        $commentsRendered = $commentBlocks === []
            ? '(no inline comments)'
            : implode("\n\n", $commentBlocks);

        return <<<PROMPT
Story: {$story?->name}

Description:
{$story?->description}

{$criterionBlock}{$taskBlock}Subtask #{$subtask->position}: {$subtask->name}

Subtask description:
{$subtask->description}

Working branch: {$this->workingBranch}
Open Pull Request: #{$this->pullRequestNumber}

{$reviewBlock}
Review comments to address ({$commentCount}):
{$commentsRendered}

Address each comment in code, then return your structured summary.
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
        ];
    }
}
