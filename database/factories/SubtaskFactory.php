<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subtask>
 */
class SubtaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'position' => fn (array $attributes): int => ((int) Subtask::query()
                ->where('task_id', $attributes['task_id'])
                ->max('position')) + 1,
            'name' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TaskStatus::Pending,
        ];
    }
}
