<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\Stories\ScenarioWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a scenario. Any of: name, acceptance_criterion_id, given_text, when_text, then_text, notes, or position.')]
class UpdateScenarioTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-scenario';

    public function handle(Request $request, ScenarioWriter $scenarios): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'scenario_id' => ['required', 'integer'],
            'acceptance_criterion_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'given_text' => ['nullable', 'string'],
            'when_text' => ['nullable', 'string'],
            'then_text' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $scenario = $this->resolveAccessibleScenario($validated['scenario_id'], $user);
        if ($scenario instanceof Response) {
            return $scenario;
        }

        $changes = [];
        foreach (['name', 'given_text', 'when_text', 'then_text', 'notes', 'position'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('acceptance_criterion_id', $validated)) {
            $changes['acceptance_criterion_id'] = $validated['acceptance_criterion_id'];
        }

        if ($changes === []) {
            return Response::error('Provide at least one field to update.');
        }

        try {
            $scenarios->update($scenario, $changes);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $scenario->refresh();

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
            'scenario_id' => $schema->integer()->description('Scenario to update.')->required(),
            'acceptance_criterion_id' => $schema->integer()->description('Optional acceptance criterion this scenario proves. Must belong to the same story.'),
            'name' => $schema->string()->description('New scenario name.'),
            'given_text' => $schema->string()->description('New Given text.'),
            'when_text' => $schema->string()->description('New When text.'),
            'then_text' => $schema->string()->description('New Then text.'),
            'notes' => $schema->string()->description('New notes. Markdown supported.'),
            'position' => $schema->integer()->description('New scenario ordering position.'),
        ];
    }
}
