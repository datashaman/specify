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
 * MCP tool: reject-story
 */
#[Description('Reject a story product contract. Terminal — no further decisions accepted on this story revision.')]
class RejectStoryTool extends Tool
{
    use RecordsApprovalDecisions;
    use ResolvesProjectAccess;

    protected string $name = 'reject-story';

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
            $decision = ApprovalDecision::Reject;
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
            'story_id' => $schema->integer()->description('Story product contract to reject.')->required(),
            'notes' => $schema->string()->description('Reason for rejection. Required.')->required(),
        ];
    }
}
