<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\ExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: generate-tasks
 *
 * Drives the planning agent for an Approved story. Generates a new current
 * plan containing tasks and subtasks.
 */
#[Description('Generate a new current implementation plan (Tasks + Subtasks) for an Approved story using the planning agent. The story must be Approved and have no Tasks in its current Plan. Generated Tasks create a fresh current Plan and reopen Plan approval so a human can review the breakdown before execution.')]
class GenerateTasksTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'generate-tasks';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request, ExecutionService $execution): Response
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

        if ($story->status !== StoryStatus::Approved) {
            return Response::error('Story must be Approved before generating a plan.');
        }

        if ($story->currentPlanTasks()->exists()) {
            return Response::error('Story already has Tasks in its current Plan. Use set-tasks / update-task to replace or edit the current Plan.');
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
            'story_id' => $schema->integer()->description('Approved story to generate a current implementation Plan for. Must have no Tasks in its current Plan.')->required(),
        ];
    }
}
