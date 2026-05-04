<?php

namespace App\Services\Stories;

use App\Enums\AgentRunKind;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    /**
     * Story-runnable AgentRuns: plan-generation runs, latest first.
     *
     * @return Collection<int, AgentRun>
     */
    public function planGenerationRuns(Story $story): Collection
    {
        return AgentRun::query()
            ->where('runnable_type', Story::class)
            ->where('runnable_id', $story->getKey())
            ->latest('id')
            ->get();
    }

    public function latestCurrentPlanRun(Story $story): ?AgentRun
    {
        return $story->currentPlanTasks
            ->flatMap->subtasks
            ->flatMap->agentRuns
            ->sortByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function currentPlanViewData(Story $story): array
    {
        $tasksByAc = $story->currentPlanTasks->groupBy('acceptance_criterion_id');
        $latestRun = $this->latestCurrentPlanRun($story);

        return [
            'story' => $story,
            'tasksByAc' => $tasksByAc,
            'unmappedTasks' => $tasksByAc->get(null, collect())->sortBy('position')->values(),
            'acs' => $story->acceptanceCriteria->sortBy('position')->values(),
            'subtaskCount' => $story->currentPlanTasks->reduce(fn ($acc, $task) => $acc + $task->subtasks->count(), 0),
            'shouldRunMode' => $this->hasActiveSubtaskRun($story),
            'branch' => $latestRun?->working_branch,
            'repo' => $latestRun?->repo,
            'planGenRuns' => $this->planGenerationRuns($story),
        ];
    }

    private function subtaskIdQueryFor(Story $story): Builder
    {
        return Subtask::query()
            ->whereIn('task_id', $story->currentPlanTasks()->select('tasks.id'))
            ->select('id');
    }
}
