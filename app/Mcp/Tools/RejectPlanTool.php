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

#[Description('Reject a plan. Terminal — no further decisions accepted on this plan revision.')]
class RejectPlanTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'reject-plan';

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
            $approval = $approvals->recordPlanDecision($plan, $user, ApprovalDecision::Reject, $validated['notes']);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        $plan->refresh();

        return Response::json([
            'approval_id' => $approval->id,
            'plan_id' => $plan->id,
            'story_id' => $plan->story_id,
            'plan_status' => $plan->status?->value,
            'decision' => 'reject',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Plan to reject.')->required(),
            'notes' => $schema->string()->description('Reason for rejection. Required.')->required(),
        ];
    }
}
