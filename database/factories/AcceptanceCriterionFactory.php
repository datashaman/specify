<?php

namespace Database\Factories;

use App\Models\AcceptanceCriterion;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcceptanceCriterion>
 */
class AcceptanceCriterionFactory extends Factory
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
            'position' => 0,
            'criterion' => fake()->sentence(),
        ];
    }
}
