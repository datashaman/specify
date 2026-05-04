<?php

namespace App\Services\Stories;

use App\Enums\AgentRunKind;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use Illuminate\Database\Eloquent\Builder;

class StoryRunProjection
{
    public function hasActiveSubtaskRun(Story $story): bool
    {
        return AgentRun::query()
            ->where('runnable_type', Subtask::class)
            ->whereIn('runnable_id', $this->subtaskIdQueryFor($story))
            ->active()
            ->exists();
    }

    public function activeConflictResolutionRun(Story $story): ?AgentRun
    {
        return AgentRun::query()
            ->where('runnable_type', Subtask::class)
            ->whereIn('runnable_id', $this->subtaskIdQueryFor($story))
            ->where('kind', AgentRunKind::ResolveConflicts->value)
            ->active()
            ->with('runnable')
            ->latest('id')
            ->first();
    }

    private function subtaskIdQueryFor(Story $story): Builder
    {
        return Subtask::query()
            ->whereIn('task_id', $story->currentPlanTasks()->select('tasks.id'))
            ->select('id');
    }
}
