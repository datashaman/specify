<?php

namespace App\Ai\Agents;

use App\Models\Story;
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
class PlanGenerator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public Story $story) {}

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are the planning agent for Specify, a system where humans approve AI actions
before they are executed. Your job is to take an approved Story (the spec) and
produce a Plan: an ordered list of concrete, executable Tasks that, together,
satisfy every acceptance criterion of the Story.

Constraints:
- Each Task must be self-contained and small enough for a coding agent to
  execute in a single run (≤ 30 minutes of focused work).
- Use `position` to give a stable ordering (0-based, ascending).
- Use `depends_on` to record blocking dependencies — a Task may only start
  once every Task whose position is listed has finished. Use this to express
  parallelism: independent Tasks share no edges.
- Never duplicate work across Tasks. If two Tasks would touch the same surface
  in conflicting ways, merge them or sequence them via `depends_on`.
- The summary should be a single paragraph capturing the strategy.

Do not include implementation snippets, code, or shell commands in Task
descriptions; describe the change in plain language and leave implementation
details for the executing coding agent.
INSTRUCTIONS;
    }

    public function buildPrompt(): string
    {
        $story = $this->story->loadMissing('feature.project', 'acceptanceCriteria');

        $criteria = $story->acceptanceCriteria
            ->map(fn ($ac) => '- '.$ac->criterion)
            ->implode("\n");

        return <<<PROMPT
Project: {$story->feature->project->name}
Feature: {$story->feature->name}
Story: {$story->name}

Description:
{$story->description}

Acceptance Criteria:
{$criteria}

Generate a plan that fully satisfies the acceptance criteria above.
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
                        'position' => $schema->integer()->min(0)->required(),
                        'depends_on' => $schema->array()
                            ->items($schema->integer()->min(0)),
                    ])
                )
                ->required(),
        ];
    }
}
