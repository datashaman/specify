<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add an acceptance criterion to a story. Position auto-increments unless supplied.')]
class AddAcceptanceCriterionTool extends Tool
{
    protected string $name = 'add-acceptance-criterion';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'criterion' => ['required', 'string'],
            'position' => ['nullable', 'integer'],
        ]);

        $story = Story::query()->with('feature')->find($validated['story_id']);
        if (! $story) {
            return Response::error('Story not found.');
        }

        if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Story not accessible.');
        }

        $position = $validated['position']
            ?? (int) ($story->acceptanceCriteria()->max('position') ?? 0) + 1;

        $ac = $story->acceptanceCriteria()->create([
            'criterion' => $validated['criterion'],
            'position' => $position,
            'met' => false,
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
            'criterion' => $schema->string()->description('Acceptance criterion text.')->required(),
            'position' => $schema->integer()->description('Position in the list. Defaults to last + 1.'),
        ];
    }
}
