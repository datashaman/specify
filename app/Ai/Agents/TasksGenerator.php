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

    /**
     * Hard cap on the rendered "Selected context assets" block. Keeps the
     * plan-generation prompt within budget when many large assets are
     * selected; items past the cap are dropped with a truncation marker.
     */
    public const CONTEXT_ASSETS_CAP_BYTES = 8192;

    public function buildPrompt(): string
    {
        $story = $this->story->loadMissing('feature.project', 'acceptanceCriteria', 'scenarios.acceptanceCriterion', 'includedContextItems');

        $criteria = $story->acceptanceCriteria
            ->sortBy('position')
            ->values()
            ->map(fn ($ac, $i) => "{$ac->position}. {$ac->statement}")
            ->implode("\n");

        $scenarios = $story->scenarios
            ->sortBy('position')
            ->values()
            ->map(function ($scenario) {
                $criterion = $scenario->acceptanceCriterion
                    ? " (AC #{$scenario->acceptanceCriterion->position})"
                    : '';

                return <<<SCENARIO
{$scenario->position}. {$scenario->name}{$criterion}
Given: {$scenario->given_text}
When: {$scenario->when_text}
Then: {$scenario->then_text}
Notes: {$scenario->notes}
SCENARIO;
            })
            ->implode("\n\n");

        $contextAssets = $this->renderContextAssetsBlock($story);

        return <<<PROMPT
Project: {$story->feature->project->name}
Feature: {$story->feature->name}
Story: {$story->name}

Description:
{$story->description}

Acceptance Criteria (position. text):
{$criteria}

Scenarios (position. Given / When / Then):
{$scenarios}
{$contextAssets}
Generate an implementation plan that fully satisfies the acceptance criteria and scenarios above. Shape Tasks around coherent implementation work that may span acceptance criteria, scenarios, or shared enabling work. Each Task must have one or more Subtasks.
PROMPT;
    }

    private function renderContextAssetsBlock(Story $story): string
    {
        $items = $story->includedContextItems;
        if ($items->isEmpty()) {
            return '';
        }

        $rendered = [];
        $usedBytes = 0;
        $droppedTitles = [];

        foreach ($items as $item) {
            $type = $item->type?->value ?? 'unknown';
            $body = trim($item->bodyForContext());
            $entry = "### {$item->title} ({$type})\n".($body === '' ? '(no extractable body)' : $body)."\n";
            $entryBytes = strlen($entry);

            if ($usedBytes + $entryBytes > self::CONTEXT_ASSETS_CAP_BYTES) {
                $droppedTitles[] = $item->title;

                continue;
            }

            $rendered[] = $entry;
            $usedBytes += $entryBytes;
        }

        if ($rendered === [] && $droppedTitles === []) {
            return '';
        }

        $body = implode("\n", $rendered);
        $note = $droppedTitles === []
            ? ''
            : "\n_Truncated: dropped ".count($droppedTitles).' item(s) over the '.self::CONTEXT_ASSETS_CAP_BYTES."-byte cap._\n";

        return "\n## Selected context assets\n\n{$body}{$note}\n";
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
                        'acceptance_criterion_position' => $schema->integer()->min(1),
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
