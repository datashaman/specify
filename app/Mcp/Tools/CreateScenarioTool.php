<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a scenario under a story. Scenarios hold Given/When/Then behaviour examples and may optionally link to an acceptance criterion in the same story.')]
class CreateScenarioTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'create-scenario';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'acceptance_criterion_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'given_text' => ['nullable', 'string'],
            'when_text' => ['nullable', 'string'],
            'then_text' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $story = $this->resolveAccessibleStory($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        $criterionId = $validated['acceptance_criterion_id'] ?? null;
        if ($criterionId !== null && ! $story->acceptanceCriteria()->whereKey($criterionId)->exists()) {
            return Response::error("acceptance_criterion_id {$criterionId} does not belong to this story.");
        }

        $position = $validated['position']
            ?? ((int) $story->scenarios()->max('position') + 1);

        $scenario = $story->scenarios()->create([
            'acceptance_criterion_id' => $criterionId,
            'position' => $position,
            'name' => $validated['name'],
            'given_text' => $validated['given_text'] ?? null,
            'when_text' => $validated['when_text'] ?? null,
            'then_text' => $validated['then_text'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return Response::json([
            'id' => $scenario->id,
            'story_id' => $scenario->story_id,
            'acceptance_criterion_id' => $scenario->acceptance_criterion_id,
            'position' => $scenario->position,
            'name' => $scenario->name,
            'given_text' => $scenario->given_text,
            'when_text' => $scenario->when_text,
            'then_text' => $scenario->then_text,
            'notes' => $scenario->notes,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story this scenario belongs to.')->required(),
            'acceptance_criterion_id' => $schema->integer()->description('Optional acceptance criterion this scenario proves. Must belong to the same story.'),
            'name' => $schema->string()->description('Scenario name. Required.')->required(),
            'given_text' => $schema->string()->description('Given context for the scenario.'),
            'when_text' => $schema->string()->description('When action for the scenario.'),
            'then_text' => $schema->string()->description('Then outcome for the scenario.'),
            'notes' => $schema->string()->description('Optional extra scenario notes. Markdown supported.'),
            'position' => $schema->integer()->description('Position in the story scenario list. Defaults to last + 1.'),
        ];
    }
}
