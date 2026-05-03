<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Task $task) {
            if ($task->story_id && ! $task->plan_id) {
                $story = Story::query()->find($task->story_id);
                $plan = $story?->current_plan_id
                    ? Plan::query()->find($story->current_plan_id)
                    : Plan::factory()->create(['story_id' => $task->story_id]);
                $task->plan_id = $plan?->id;
            }

            if ($task->plan_id && ! $task->story_id) {
                $task->story_id = Plan::query()->find($task->plan_id)?->story_id;
            }
        })->afterCreating(function (Task $task) {
            if ($task->story && ! $task->story->current_plan_id) {
                $task->story->forceFill(['current_plan_id' => $task->plan_id])->save();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => null,
            'story_id' => Story::factory(),
            'acceptance_criterion_id' => null,
            'scenario_id' => null,
            'position' => 1,
            'name' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TaskStatus::Pending,
        ];
    }
}
