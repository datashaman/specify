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

#[Description('Record an Approve decision on the story’s current plan. Authorisation: user must have approver rights in the plan’s project. Notes optional.')]
class ApprovePlanTool extends Tool
{
    use RecordsApprovalDecisions;
    use ResolvesProjectAccess;

    protected string $name = 'approve-plan';

    public function handle(Request $request, ApprovalService $approvals): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        $plan = $this->resolveCurrentPlanForApproval($validated['plan_id'], $user);
        if ($plan instanceof Response) {
            return $plan;
        }

        try {
            $decision = ApprovalDecision::Approve;
            $approval = $approvals->recordPlanDecision($plan, $user, $decision, $validated['notes'] ?? null);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return $this->planApprovalResponse($plan, $approval, $decision);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('Current plan to approve.')->required(),
            'notes' => $schema->string()->description('Optional approval notes.'),
        ];
    }
}
