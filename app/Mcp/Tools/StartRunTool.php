<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Story;
use App\Services\ExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Start (or resume) execution of an Approved story. Dispatches agent runs for the next actionable subtasks (parent task dependencies satisfied AND lower-position siblings done). The story must already be Approved.')]
class StartRunTool extends Tool
{
    protected string $name = 'start-run';

    public function handle(Request $request, ExecutionService $execution): Response
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
            $execution->startStoryExecution($story);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        $story->refresh();

        return Response::json([
            'story_id' => $story->id,
            'status' => $story->status?->value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story to execute. Must be Approved.')->required(),
        ];
    }
}
