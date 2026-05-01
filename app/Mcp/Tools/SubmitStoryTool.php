<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: submit-story
 */
#[Description('Submit a story for approval (Draft → PendingApproval). Errors if the story has been rejected.')]
class SubmitStoryTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'submit-story';

    /**
     * Handle the MCP tool invocation.
     */
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

        try {
            $story->submitForApproval();
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        $story->refresh();

        return Response::json([
            'id' => $story->id,
            'status' => $story->status?->value,
            'revision' => $story->revision,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story to submit.')->required(),
        ];
    }
}
