<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Scenario;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List scenarios under a story in position order. Each scenario may optionally link to an acceptance criterion it proves.')]
class ListScenariosTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-scenarios';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $storyId = $request->integer('story_id');
        if (! $storyId) {
            return Response::error('story_id is required.');
        }

        $story = $this->resolveAccessibleStory($storyId, $user);
        if ($story instanceof Response) {
            return $story;
        }

        $scenarios = $story->scenarios()->with('acceptanceCriterion:id,position,statement')->orderBy('position')->get();

        return Response::json([
            'story_id' => $story->id,
            'count' => $scenarios->count(),
            'scenarios' => $scenarios->map(fn (Scenario $scenario) => [
                'id' => $scenario->id,
                'acceptance_criterion_id' => $scenario->acceptance_criterion_id,
                'position' => $scenario->position,
                'name' => $scenario->name,
                'given_text' => $scenario->given_text,
                'when_text' => $scenario->when_text,
                'then_text' => $scenario->then_text,
                'notes' => $scenario->notes,
                'acceptance_criterion' => $scenario->acceptanceCriterion ? [
                    'id' => $scenario->acceptanceCriterion->id,
                    'position' => $scenario->acceptanceCriterion->position,
                    'statement' => $scenario->acceptanceCriterion->statement,
                ] : null,
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story whose scenarios to list.')->required(),
        ];
    }
}
