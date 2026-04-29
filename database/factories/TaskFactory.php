<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'position' => 0,
            'name' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TaskStatus::Pending,
        ];
    }
}
