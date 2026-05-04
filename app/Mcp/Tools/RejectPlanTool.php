<?php

namespace App\Mcp\Tools;

use App\Enums\ApprovalDecision;
use App\Mcp\Concerns\RecordsApprovalDecisions;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reject the story’s current plan. Terminal — no further decisions accepted on this plan revision.')]
class RejectPlanTool extends Tool
{
    use RecordsApprovalDecisions;
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

        $plan = $this->resolveCurrentPlanForApproval($validated['plan_id'], $user);
        if ($plan instanceof Response) {
            return $plan;
        }

        try {
            $decision = ApprovalDecision::Reject;
            $approval = $approvals->recordPlanDecision($plan, $user, $decision, $validated['notes']);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return $this->planApprovalResponse($plan, $approval, $decision);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Current plan to reject.')->required(),
            'notes' => $schema->string()->description('Reason for rejection. Required.')->required(),
        ];
    }
}
