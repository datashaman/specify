<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Engineering contract for one acceptance criterion.
 *
 * Holds 1+ Subtasks (the actual executor steps) and a DAG of task-level
 * `dependencies`/`dependents` (cycle-checked via `dependsOnTransitively`).
 */
#[Fillable(['story_id', 'acceptance_criterion_id', 'position', 'name', 'description', 'status'])]
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

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function acceptanceCriterion(): BelongsTo
    {
        return $this->belongsTo(AcceptanceCriterion::class);
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
        if ($this->is($other)) {
            throw new InvalidArgumentException('A task cannot depend on itself.');
        }

        if ($this->story_id !== $other->story_id) {
            throw new InvalidArgumentException('Task dependencies must live in the same story.');
        }

        if ($other->dependsOnTransitively($this)) {
            throw new InvalidArgumentException('Adding this dependency would create a cycle.');
        }

        $this->dependencies()->syncWithoutDetaching([$other->getKey()]);
    }

    public function dependsOnTransitively(self $candidate): bool
    {
        $visited = [];
        $stack = [$this->getKey()];

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $deps = self::find($id)?->dependencies()->pluck('tasks.id')->all() ?? [];
            foreach ($deps as $depId) {
                if ($depId === $candidate->getKey()) {
                    return true;
                }
                $stack[] = $depId;
            }
        }

        return false;
    }

    public function isReady(): bool
    {
        return $this->dependencies()
            ->where('status', '!=', TaskStatus::Done->value)
            ->doesntExist();
    }
}
