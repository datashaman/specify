<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

#[Description('Get a plan in detail, including task and subtask counts and whether it is the story\'s current plan.')]
class GetPlanTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'get-plan';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $planId = $request->integer('plan_id');
        if (! $planId) {
            return Response::error('plan_id is required.');
        }

        $plan = $this->resolveAccessiblePlan($planId, $user);
        if ($plan instanceof Response) {
            return $plan;
        }

        $plan->loadCount('tasks');
        $subtaskCount = $plan->tasks()->withCount('subtasks')->get()->sum('subtasks_count');

        return Response::json([
            'id' => $plan->id,
            'story_id' => $plan->story_id,
            'version' => $plan->version,
            'name' => $plan->name,
            'summary' => $plan->summary,
            'design_notes' => $plan->design_notes,
            'implementation_notes' => $plan->implementation_notes,
            'risks' => $plan->risks,
            'assumptions' => $plan->assumptions,
            'source' => $plan->source?->value,
            'source_label' => $plan->source_label,
            'status' => $plan->status?->value,
            'tasks_count' => $plan->tasks_count,
            'subtasks_count' => $subtaskCount,
            'is_current' => (int) $plan->story->current_plan_id === (int) $plan->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Plan to fetch.')->required(),
        ];
    }
}
