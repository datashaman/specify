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
            $plan = $task->plan()->first();
            $story = $plan?->story;

            if ($story && ! $story->current_plan_id) {
                $story->forceFill(['current_plan_id' => $plan->getKey()])->save();
            }
        });
    }

    public function forCurrentPlanOf(Story $story): static
    {
        return $this->state(function () use ($story) {
            $planId = $story->fresh()?->current_plan_id;
            if ($planId) {
                return ['plan_id' => $planId];
            }

            return [
                'plan_id' => Plan::factory()
                    ->for($story)
                    ->afterCreating(function (Plan $plan) use ($story) {
                        $story->forceFill(['current_plan_id' => $plan->getKey()])->save();
                    }),
            ];
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
