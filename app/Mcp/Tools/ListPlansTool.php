<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Plan;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List plans (revisions) for a story, newest first.')]
class ListPlansTool extends Tool
{
    protected string $name = 'list-plans';

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

        $plans = $story->plans()
            ->withCount('tasks')
            ->orderByDesc('version')
            ->get(['id', 'story_id', 'version', 'summary', 'status']);

        return Response::json([
            'story_id' => $story->id,
            'current_plan_id' => $story->current_plan_id,
            'plans' => $plans->map(fn (Plan $p) => [
                'id' => $p->id,
                'version' => $p->version,
                'summary' => $p->summary,
                'status' => $p->status?->value,
                'tasks_count' => $p->tasks_count,
                'is_current' => $p->id === $story->current_plan_id,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story whose plans to list.')->required(),
        ];
    }
}
