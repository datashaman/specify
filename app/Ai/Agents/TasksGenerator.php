<?php

namespace App\Ai\Agents;

use App\Models\Story;
use App\Services\Prompts\PromptLoader;
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
        return app(PromptLoader::class)->load('tasks-generator');
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
