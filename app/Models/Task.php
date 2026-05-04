<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Services\Tasks\TaskDependencyGraph;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Actionable work item under a Plan.
 */
#[Fillable(['plan_id', 'acceptance_criterion_id', 'scenario_id', 'position', 'name', 'description', 'status'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function acceptanceCriterion(): BelongsTo
    {
        return $this->belongsTo(AcceptanceCriterion::class);
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    public function addDependency(self $other): void
    {
        app(TaskDependencyGraph::class)->addDependency($this, $other);
    }

    public function dependsOnTransitively(self $candidate): bool
    {
        return app(TaskDependencyGraph::class)->dependsOnTransitively($this, $candidate);
    }

    public function isReady(): bool
    {
        return app(TaskDependencyGraph::class)->isReady($this);
    }
}
