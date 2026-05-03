<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Plan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List plans under a story. Returns newest version first and marks which plan is current.')]
class ListPlansTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-plans';

    public function handle(Request $request): Response
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

        $plans = Plan::query()
            ->where('story_id', $story->id)
            ->withCount('tasks')
            ->orderByDesc('version')
            ->get();

        return Response::json([
            'story_id' => $story->id,
            'count' => $plans->count(),
            'plans' => $plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'version' => $plan->version,
                'revision' => $plan->revision,
                'name' => $plan->name,
                'summary' => $plan->summary,
                'source' => $plan->source?->value,
                'source_label' => $plan->source_label,
                'status' => $plan->status?->value,
                'tasks_count' => $plan->tasks_count,
                'is_current' => (int) $story->current_plan_id === (int) $plan->id,
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story whose plans to list.')->required(),
        ];
    }
}
