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
 * Successor to the legacy `generate-plan` slug. ADR-0002 retired the Plan
 * model; the tool slug is renamed here to align the MCP surface with the
 * internal Story → Task → Subtask hierarchy. `GeneratePlanTool` remains as
 * a deprecation shim that forwards to this handler with a warning log.
 */
#[Description('Generate the task list (tasks + subtasks) for an Approved story using the planning agent. One task per acceptance criterion, each with one or more subtasks. The story must be Approved and have no existing tasks. Generated tasks reopen approval — the story flips back to PendingApproval (revision bumped) so a human can review the breakdown before execution.')]
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
            return Response::error('Story must be Approved before generating tasks.');
        }

        if ($story->tasks()->exists()) {
            return Response::error('Story already has tasks. Use set-tasks / update-task to edit them.');
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
            'story_id' => $schema->integer()->description('Approved story to generate tasks for. Must have no existing tasks.')->required(),
        ];
    }
}
