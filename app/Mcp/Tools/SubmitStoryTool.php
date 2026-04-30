<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Submit a story for approval (Draft → PendingApproval). Errors if the story has been rejected.')]
class SubmitStoryTool extends Tool
{
    protected string $name = 'submit-story';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $storyId = $request->integer('story_id');
        if (! $storyId) {
            return Response::error('story_id is required.');
        }

        $story = Story::query()->with('feature')->find($storyId);
        if (! $story) {
            return Response::error('Story not found.');
        }

        if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Story not accessible.');
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
