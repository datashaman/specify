<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Plan;
use App\Services\ExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Start (or resume) execution of an Approved plan. Dispatches agent runs for tasks whose dependencies are satisfied. Plan must already be Approved.')]
class StartRunTool extends Tool
{
    protected string $name = 'start-run';

    public function handle(Request $request, ExecutionService $execution): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $planId = $request->integer('plan_id');
        if (! $planId) {
            return Response::error('plan_id is required.');
        }

        $plan = Plan::query()->with('story.feature')->find($planId);
        if (! $plan) {
            return Response::error('Plan not found.');
        }

        if (! in_array($plan->story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Plan not accessible.');
        }

        try {
            $execution->startPlanExecution($plan);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        $plan->refresh();

        return Response::json([
            'plan_id' => $plan->id,
            'status' => $plan->status?->value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Plan to execute. Must be Approved.')->required(),
        ];
    }
}
