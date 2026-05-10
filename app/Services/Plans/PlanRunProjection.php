<?php

namespace App\Services\Plans;

use App\Models\AgentRun;
use App\Models\Plan;

/**
 * Derived run/branch/repo view for a Plan, mirroring StoryRunProjection but
 * scoped to a single Plan rather than the story's current plan.
 *
 * Self-loads `tasks.subtasks.agentRuns.repo` via loadMissing so callers don't
 * have to remember the eager-load shape; if they already loaded it, this is
 * a no-op.
 */
class PlanRunProjection
{
    /**
     * Most recent AgentRun across this plan's tasks/subtasks (highest id wins),
     * or null if no run exists yet.
     */
    public function latestRun(Plan $plan): ?AgentRun
    {
        $plan->loadMissing('tasks.subtasks.agentRuns.repo');

        $latest = null;
        foreach ($plan->tasks as $task) {
            foreach ($task->subtasks as $subtask) {
                foreach ($subtask->agentRuns as $run) {
                    if ($latest === null || $run->id > $latest->id) {
                        $latest = $run;
                    }
                }
            }
        }

        return $latest;
    }
}
