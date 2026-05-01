<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add an acceptance criterion to a story. Position auto-increments unless supplied.')]
class AddAcceptanceCriterionTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'add-acceptance-criterion';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'criterion' => ['required', 'string'],
            'position' => ['nullable', 'integer'],
        ]);

        $story = $this->resolveAccessibleStory($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        $position = $validated['position']
            ?? (int) ($story->acceptanceCriteria()->max('position') ?? 0) + 1;

        $ac = $story->acceptanceCriteria()->create([
            'criterion' => $validated['criterion'],
            'position' => $position,
        ]);

        return Response::json([
            'id' => $ac->id,
            'story_id' => $ac->story_id,
            'position' => $ac->position,
            'criterion' => $ac->criterion,
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
            'criterion' => $schema->string()->description('Observable behaviour the story must satisfy. Phrase as a "given/when/then" or plain "the system X when Y." Not an implementation step.')->required(),
            'position' => $schema->integer()->description('Position in the list. Defaults to last + 1.'),
        ];
    }
}
