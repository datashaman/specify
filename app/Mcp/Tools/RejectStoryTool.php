<?php

namespace App\Mcp\Tools;

use App\Enums\ApprovalDecision;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Story;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: reject-story
 */
#[Description('Reject a story. Terminal — no further decisions accepted on this story revision.')]
class RejectStoryTool extends Tool
{
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

        $story = Story::query()->with('feature.project')->find($validated['story_id']);
        if (! $story) {
            return Response::error('Story not found.');
        }

        $project = $story->feature->project;
        if (! $project || ! $user->canApproveInProject($project)) {
            return Response::error('You do not have approver rights in this project.');
        }

        try {
            $approval = $approvals->recordDecision($story, $user, ApprovalDecision::Reject, $validated['notes']);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        $story->refresh();

        return Response::json([
            'approval_id' => $approval->id,
            'story_id' => $story->id,
            'story_status' => $story->status?->value,
            'decision' => 'reject',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story to reject.')->required(),
            'notes' => $schema->string()->description('Reason for rejection. Required.')->required(),
        ];
    }
}
