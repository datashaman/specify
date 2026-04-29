<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'version' => 1,
            'summary' => fake()->paragraph(),
            'status' => PlanStatus::Draft,
        ];
    }
}
