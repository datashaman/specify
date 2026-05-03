<?php

namespace Database\Factories;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'version' => 1,
            'revision' => 1,
            'name' => fake()->sentence(3),
            'summary' => fake()->paragraph(),
            'design_notes' => fake()->paragraph(),
            'implementation_notes' => fake()->paragraph(),
            'risks' => fake()->sentence(),
            'assumptions' => fake()->sentence(),
            'source' => PlanSource::Human,
            'source_label' => null,
            'status' => PlanStatus::Draft,
        ];
    }
}
