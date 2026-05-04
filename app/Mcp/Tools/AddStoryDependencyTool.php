<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\Stories\StoryDependencyGraph;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: add-story-dependency
 */
#[Description('Mark a story as depending on another story. Both stories must be in projects the user can access.')]
class AddStoryDependencyTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'add-story-dependency';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request, StoryDependencyGraph $dependencies): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'depends_on_story_id' => ['required', 'integer', 'different:story_id'],
        ]);

        $story = $this->resolveAccessibleStory(
            $validated['story_id'],
            $user,
            notFoundMessage: 'One or both stories not found.',
            forbiddenMessage: 'One or both stories are not accessible.',
        );
        if ($story instanceof Response) {
            return $story;
        }
        $dependency = $this->resolveAccessibleStory(
            $validated['depends_on_story_id'],
            $user,
            notFoundMessage: 'One or both stories not found.',
            forbiddenMessage: 'One or both stories are not accessible.',
        );
        if ($dependency instanceof Response) {
            return $dependency;
        }

        try {
            $dependencies->addDependency($story, $dependency);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

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
