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
 * MCP tool: request-story-changes
 */
#[Description('Request changes on a story. Resets prior approvals and moves the story to ChangesRequested.')]
class RequestStoryChangesTool extends Tool
{
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

        $story = Story::query()->with('feature.project')->find($validated['story_id']);
        if (! $story) {
            return Response::error('Story not found.');
        }

        $project = $story->feature->project;
        if (! $project || ! $user->canApproveInProject($project)) {
            return Response::error('You do not have approver rights in this project.');
        }

        try {
            $approval = $approvals->recordDecision($story, $user, ApprovalDecision::ChangesRequested, $validated['notes']);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        $story->refresh();

        return Response::json([
            'approval_id' => $approval->id,
            'story_id' => $story->id,
            'story_status' => $story->status?->value,
            'decision' => 'changes_requested',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story to request changes on.')->required(),
            'notes' => $schema->string()->description('What needs to change. Required.')->required(),
        ];
    }
}
