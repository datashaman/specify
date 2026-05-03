<?php

namespace Database\Factories;

use App\Models\AcceptanceCriterion;
use App\Models\Scenario;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scenario>
 */
class ScenarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'acceptance_criterion_id' => null,
            'position' => 1,
            'name' => fake()->sentence(4),
            'given_text' => fake()->sentence(),
            'when_text' => fake()->sentence(),
            'then_text' => fake()->sentence(),
            'notes' => fake()->optional()->paragraph(),
        ];
    }

    public function forCriterion(AcceptanceCriterion $criterion): static
    {
        return $this->state(fn () => [
            'story_id' => $criterion->story_id,
            'acceptance_criterion_id' => $criterion->id,
        ]);
    }
}
