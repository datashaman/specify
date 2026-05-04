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
            'position' => fn (array $attributes): int => ((int) AcceptanceCriterion::query()
                ->where('story_id', $attributes['story_id'])
                ->max('position')) + 1,
            'statement' => fake()->sentence(),
        ];
    }
}
