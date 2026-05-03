<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: add-acceptance-criterion
 */
#[Description('Add an acceptance criterion to a story. Position auto-increments unless supplied.')]
class AddAcceptanceCriterionTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'add-acceptance-criterion';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'criterion' => ['nullable', 'string'],
            'statement' => ['nullable', 'string'],
            'position' => ['nullable', 'integer'],
        ]);

        $story = $this->resolveAccessibleStory($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        $statement = $validated['statement'] ?? $validated['criterion'] ?? null;
        if (! is_string($statement) || trim($statement) === '') {
            return Response::error('statement is required.');
        }

        $position = $validated['position']
            ?? (int) ($story->acceptanceCriteria()->max('position') ?? 0) + 1;

        $ac = $story->acceptanceCriteria()->create([
            'statement' => $statement,
            'position' => $position,
        ]);

        return Response::json([
            'id' => $ac->id,
            'story_id' => $ac->story_id,
            'position' => $ac->position,
            'statement' => $ac->statement,
            'met' => (bool) $ac->met,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story to add the criterion to.')->required(),
            'criterion' => $schema->string()->description('Deprecated alias for statement. Observable behaviour the story must satisfy as one atomic rule statement.'),
            'statement' => $schema->string()->description('Observable behaviour the story must satisfy. Use one atomic rule statement, not a full Given/When/Then scenario.'),
            'position' => $schema->integer()->description('Position in the list. Defaults to last + 1.'),
        ];
    }
}
