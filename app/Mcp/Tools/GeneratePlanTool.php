<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Mcp\Auth;
use App\Models\Story;
use App\Services\ExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Generate the plan (tasks + subtasks) for an Approved story using the planning agent. One task per acceptance criterion, each with one or more subtasks. The story must be Approved and have no existing tasks. Generated plan reopens approval — the story flips back to PendingApproval (revision bumped) so a human can review the breakdown before execution.')]
class GeneratePlanTool extends Tool
{
    protected string $name = 'generate-plan';

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

        if ($story->status !== StoryStatus::Approved) {
            return Response::error('Story must be Approved before generating a plan.');
        }

        if ($story->tasks()->exists()) {
            return Response::error('Story already has a plan. Use set-tasks / update-task to edit it.');
        }

        $run = $execution->dispatchTaskGeneration($story);

        return Response::json([
            'story_id' => $story->id,
            'agent_run_id' => $run->id,
            'status' => $run->status?->value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Approved story to generate the plan for. Must have no existing tasks.')->required(),
        ];
    }
}
