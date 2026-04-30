<?php

namespace App\Ai\Agents;

use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[UseSmartestModel]
#[MaxTokens(4096)]
class TasksGenerator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public Story $story) {}

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are the planning agent for Specify, a system where humans approve AI actions
before they are executed. Your job is to take a Story (the spec) and produce a
task list: one Task per Acceptance Criterion, each broken down into 1+ ordered
Subtasks that the executor will run one at a time.

Constraints:
- Produce exactly one Task per Acceptance Criterion. Reference the criterion by
  the exact position number shown next to it in the prompt, using
  `acceptance_criterion_position`. Do not renumber.
- Each Subtask must be self-contained and small enough for a coding agent to
  execute in a single run (≤ 30 minutes of focused work).
- Use Subtask `position` to give a stable in-task ordering (1-based, ascending).
- Use Task `position` for the overall task ordering (1-based, ascending).
- Use Task `depends_on` to record blocking dependencies between tasks. A Task
  may only start once every Task whose position is listed has finished. Subtasks
  themselves run sequentially within their parent Task — there are no subtask
  dependencies.
- Never duplicate work. If two Tasks would touch the same surface in conflicting
  ways, sequence them via `depends_on`.
- The summary should be a single paragraph capturing the overall strategy.

Do not include implementation snippets, code, or shell commands in Task or
Subtask descriptions; describe the change in plain language and leave
implementation details for the executing coding agent.
INSTRUCTIONS;
    }

    public function buildPrompt(): string
    {
        $story = $this->story->loadMissing('feature.project', 'acceptanceCriteria');

        $criteria = $story->acceptanceCriteria
            ->sortBy('position')
            ->values()
            ->map(fn ($ac, $i) => "{$ac->position}. {$ac->criterion}")
            ->implode("\n");

        return <<<PROMPT
Project: {$story->feature->project->name}
Feature: {$story->feature->name}
Story: {$story->name}

Description:
{$story->description}

Acceptance Criteria (position. text):
{$criteria}

Generate a task list that fully satisfies the acceptance criteria above. One Task per Acceptance Criterion, each with one or more Subtasks.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'tasks' => $schema->array()
                ->items(
                    $schema->object(fn ($schema) => [
                        'name' => $schema->string()->required(),
                        'description' => $schema->string()->required(),
                        'position' => $schema->integer()->min(1)->required(),
                        'acceptance_criterion_position' => $schema->integer()->min(1)->required(),
                        'depends_on' => $schema->array()
                            ->items($schema->integer()->min(1)),
                        'subtasks' => $schema->array()
                            ->items(
                                $schema->object(fn ($schema) => [
                                    'name' => $schema->string()->required(),
                                    'description' => $schema->string()->required(),
                                    'position' => $schema->integer()->min(1)->required(),
                                ])
                            )
                            ->required(),
                    ])
                )
                ->required(),
        ];
    }
}
