<?php

namespace App\Mcp\Tools;

use App\Enums\ApprovalDecision;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Request changes on a plan. Resets prior effective plan approvals and keeps the plan pending approval.')]
class RequestPlanChangesTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'request-plan-changes';

    public function handle(Request $request, ApprovalService $approvals): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'notes' => ['required', 'string'],
        ]);

        $plan = $this->resolveAccessiblePlan($validated['plan_id'], $user);
        if ($plan instanceof Response) {
            return $plan;
        }

        $project = $plan->story?->feature?->project;
        if (! $project || ! $user->canApproveInProject($project)) {
            return Response::error('You do not have approver rights in this project.');
        }

        try {
            $approval = $approvals->recordPlanDecision($plan, $user, ApprovalDecision::ChangesRequested, $validated['notes']);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        $plan->refresh();

        return Response::json([
            'approval_id' => $approval->id,
            'plan_id' => $plan->id,
            'story_id' => $plan->story_id,
            'plan_status' => $plan->status?->value,
            'decision' => 'changes_requested',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Plan to request changes on.')->required(),
            'notes' => $schema->string()->description('What needs to change. Required.')->required(),
        ];
    }
}
