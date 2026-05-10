<?php

namespace App\Services\Plans;

use App\Models\AgentRun;
use App\Models\Plan;

/**
 * Derived run/branch/repo view for a Plan, mirroring StoryRunProjection but
 * scoped to a single Plan rather than the story's current plan.
 *
 * Operates on already-loaded relations so callers can rely on their own
 * eager-load shape without paying for an extra query here.
 */
class PlanRunProjection
{
    /**
     * Most recent AgentRun across this plan's tasks/subtasks (highest id wins),
     * or null if no run exists yet.
     */
    public function latestRun(Plan $plan): ?AgentRun
    {
        return $plan->tasks
            ->flatMap->subtasks
            ->flatMap->agentRuns
            ->sortByDesc('id')
            ->first();
    }
}
