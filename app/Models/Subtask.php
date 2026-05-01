<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\SubtaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Engineering step the executor runs. One `ExecuteSubtaskJob` invocation per Subtask.
 *
 * Subtasks under a Task run in `position` order; the next one isn't dispatched
 * until the previous Subtask is Done (see `ExecutionService::nextActionableSubtasks`).
 */
#[Fillable(['task_id', 'position', 'name', 'description', 'status', 'proposed_by_run_id'])]
class Subtask extends Model
{
    /** @use HasFactory<SubtaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * AgentRun that appended this Subtask via the executor's proposed_subtasks
     * field (ADR-0005). NULL for human- or generator-authored Subtasks.
     */
    public function proposedByRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'proposed_by_run_id');
    }

    public function agentRuns(): MorphMany
    {
        return $this->morphMany(AgentRun::class, 'runnable');
    }

    public function latestRun(): ?AgentRun
    {
        return $this->agentRuns()->latest('id')->first();
    }
}
