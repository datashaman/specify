<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Plan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get a plan in detail, including its task graph (positions, names, dependencies, status).')]
class GetPlanTool extends Tool
{
    protected string $name = 'get-plan';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $planId = $request->integer('plan_id');
        if (! $planId) {
            return Response::error('plan_id is required.');
        }

        $plan = Plan::query()->with(['story.feature', 'tasks.dependencies:id'])->find($planId);
        if (! $plan) {
            return Response::error('Plan not found.');
        }

        if (! in_array($plan->story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Plan not accessible.');
        }

        $idToPosition = $plan->tasks->mapWithKeys(fn ($t) => [$t->id => $t->position])->all();

        return Response::json([
            'id' => $plan->id,
            'story_id' => $plan->story_id,
            'version' => $plan->version,
            'summary' => $plan->summary,
            'status' => $plan->status?->value,
            'is_current' => $plan->id === $plan->story->current_plan_id,
            'tasks' => $plan->tasks->map(fn ($t) => [
                'id' => $t->id,
                'position' => $t->position,
                'name' => $t->name,
                'description' => $t->description,
                'status' => $t->status?->value,
                'depends_on_positions' => $t->dependencies
                    ->map(fn ($d) => $idToPosition[$d->id] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Plan to fetch.')->required(),
        ];
    }
}
