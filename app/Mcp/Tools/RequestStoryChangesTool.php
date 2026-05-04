<?php

namespace App\Mcp\Tools;

use App\Enums\ApprovalDecision;
use App\Mcp\Concerns\RecordsApprovalDecisions;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: request-story-changes
 */
#[Description('Request changes on a story product contract. Resets prior approvals and moves the story to ChangesRequested.')]
class RequestStoryChangesTool extends Tool
{
    use RecordsApprovalDecisions;
    use ResolvesProjectAccess;

    protected string $name = 'request-story-changes';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request, ApprovalService $approvals): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'notes' => ['required', 'string'],
        ]);

        $story = $this->resolveStoryForApproval($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        try {
            $decision = ApprovalDecision::ChangesRequested;
            $approval = $approvals->recordDecision($story, $user, $decision, $validated['notes']);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return $this->storyApprovalResponse($story, $approval, $decision);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story product contract to request changes on.')->required(),
            'notes' => $schema->string()->description('What needs to change. Required.')->required(),
        ];
    }
}
