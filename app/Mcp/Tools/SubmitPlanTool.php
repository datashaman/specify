<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Submit a plan for approval (Draft → PendingApproval). Errors if the plan has been rejected or has no tasks.')]
class SubmitPlanTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'submit-plan';

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

        try {
            $plan->submitForApproval();
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        $plan->refresh();

        return Response::json([
            'id' => $plan->id,
            'story_id' => $plan->story_id,
            'status' => $plan->status?->value,
            'revision' => $plan->revision,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Plan to submit.')->required(),
        ];
    }
}
