<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Mark a story as depending on another story. Both stories must be in projects the user can access.')]
class AddStoryDependencyTool extends Tool
{
    protected string $name = 'add-story-dependency';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'depends_on_story_id' => ['required', 'integer', 'different:story_id'],
        ]);

        $story = Story::query()->with('feature')->find($validated['story_id']);
        $dependency = Story::query()->with('feature')->find($validated['depends_on_story_id']);

        if (! $story || ! $dependency) {
            return Response::error('One or both stories not found.');
        }

        $accessible = $user->accessibleProjectIds();
        if (! in_array($story->feature->project_id, $accessible, true)
            || ! in_array($dependency->feature->project_id, $accessible, true)) {
            return Response::error('One or both stories are not accessible.');
        }

        $story->dependencies()->syncWithoutDetaching([$dependency->id]);

        return Response::json([
            'story_id' => $story->id,
            'depends_on_story_id' => $dependency->id,
            'dependencies' => $story->dependencies()->pluck('stories.id')->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Dependent story.')->required(),
            'depends_on_story_id' => $schema->integer()->description('Story that must be done first.')->required(),
        ];
    }
}
