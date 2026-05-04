<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use InvalidArgumentException;

class TaskDependencyGraph
{
    public function addDependency(Task $task, Task $dependency): void
    {
        if ($task->is($dependency)) {
            throw new InvalidArgumentException('A task cannot depend on itself.');
        }

        if ((int) $task->plan_id !== (int) $dependency->plan_id) {
            throw new InvalidArgumentException('Task dependencies must live in the same plan.');
        }

        if ($this->dependsOnTransitively($dependency, $task)) {
            throw new InvalidArgumentException('Adding this dependency would create a cycle.');
        }

        $task->dependencies()->syncWithoutDetaching([$dependency->getKey()]);
    }

    public function dependsOnTransitively(Task $task, Task $candidate): bool
    {
        $visited = [];
        $stack = [$task->getKey()];

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $deps = Task::find($id)?->dependencies()->pluck('tasks.id')->all() ?? [];
            foreach ($deps as $depId) {
                if ($depId === $candidate->getKey()) {
                    return true;
                }
                $stack[] = $depId;
            }
        }

        return false;
    }

    public function isReady(Task $task): bool
    {
        return $task->dependencies()
            ->where('status', '!=', TaskStatus::Done->value)
            ->doesntExist();
    }
}
