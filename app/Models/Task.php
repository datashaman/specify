<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

#[Fillable(['plan_id', 'position', 'name', 'description', 'status'])]
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

    public function agentRuns(): MorphMany
    {
        return $this->morphMany(AgentRun::class, 'runnable');
    }

    public function latestRun(): ?AgentRun
    {
        return $this->agentRuns()->latest('id')->first();
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

        if ($this->plan_id !== $other->plan_id) {
            throw new InvalidArgumentException('Task dependencies must live in the same plan.');
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
