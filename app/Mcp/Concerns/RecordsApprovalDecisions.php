<?php

namespace App\Mcp\Concerns;

use App\Enums\ApprovalDecision;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\User;
use Laravel\Mcp\Response;

trait RecordsApprovalDecisions
{
    protected function resolveStoryForApproval(int $storyId, User $user): Story|Response
    {
        $story = $this->resolveAccessibleStory($storyId, $user);
        if ($story instanceof Response) {
            return $story;
        }

        $story->loadMissing('feature.project');

        if (! $user->canApproveInProject($story->feature->project)) {
            return Response::error('You do not have approver rights in this project.');
        }

        return $story;
    }

    protected function resolveCurrentPlanForApproval(int $planId, User $user): Plan|Response
    {
        $plan = $this->resolveAccessiblePlan($planId, $user);
        if ($plan instanceof Response) {
            return $plan;
        }

        $plan->loadMissing('story.feature.project');

        if (! $plan->isCurrent()) {
            return Response::error('Only the story\'s current plan can receive approval decisions.');
        }

        if (! $user->canApproveInProject($plan->story->feature->project)) {
            return Response::error('You do not have approver rights in this project.');
        }

        return $plan;
    }

    protected function storyApprovalResponse(Story $story, StoryApproval $approval, ApprovalDecision $decision): Response
    {
        $story->refresh();

        return Response::json([
            'approval_id' => $approval->id,
            'story_id' => $story->id,
            'story_status' => $story->status?->value,
            'decision' => $decision->value,
        ]);
    }

    protected function planApprovalResponse(Plan $plan, PlanApproval $approval, ApprovalDecision $decision): Response
    {
        $plan->refresh();

        return Response::json([
            'approval_id' => $approval->id,
            'plan_id' => $plan->id,
            'story_id' => $plan->story_id,
            'plan_status' => $plan->status?->value,
            'decision' => $decision->value,
        ]);
    }
}
