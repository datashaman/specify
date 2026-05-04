<?php

namespace App\Services\Approvals;

use App\Models\ApprovalPolicy;
use App\Models\Story;
use App\Models\Team;

class ApprovalPolicyResolver
{
    public function forStory(Story $story): ApprovalPolicy
    {
        $storyPolicy = ApprovalPolicy::query()
            ->where('scope_type', ApprovalPolicy::SCOPE_STORY)
            ->where('scope_id', $story->getKey())
            ->first();

        if ($storyPolicy) {
            return $storyPolicy;
        }

        $project = $story->feature?->project;
        if ($project) {
            $projectPolicy = ApprovalPolicy::query()
                ->where('scope_type', ApprovalPolicy::SCOPE_PROJECT)
                ->where('scope_id', $project->getKey())
                ->first();

            if ($projectPolicy) {
                return $projectPolicy;
            }
        }

        $workspaceId = $project?->team_id
            ? Team::query()->whereKey($project->team_id)->value('workspace_id')
            : null;

        if ($workspaceId) {
            $workspacePolicy = ApprovalPolicy::query()
                ->where('scope_type', ApprovalPolicy::SCOPE_WORKSPACE)
                ->where('scope_id', $workspaceId)
                ->first();

            if ($workspacePolicy) {
                return $workspacePolicy;
            }
        }

        return ApprovalPolicy::default();
    }
}
