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
        return $this->afterCreating(function (Task $task) {
            $plan = $task->plan;
            $story = $plan?->story;

            if ($story && ! $story->current_plan_id) {
                $story->forceFill(['current_plan_id' => $plan->getKey()])->save();
            }
        });
    }

    public function forStory(Story $story): static
    {
        return $this->state(function () use ($story) {
            $freshStory = $story->fresh();
            $plan = $freshStory?->current_plan_id
                ? Plan::query()->find($freshStory->current_plan_id)
                : Plan::factory()->create(['story_id' => $story->getKey()]);

            if ($plan && ! $freshStory?->current_plan_id) {
                $story->forceFill(['current_plan_id' => $plan->getKey()])->save();
            }

            return ['plan_id' => $plan?->getKey()];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'acceptance_criterion_id' => null,
            'scenario_id' => null,
            'position' => 1,
            'name' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TaskStatus::Pending,
        ];
    }
}
