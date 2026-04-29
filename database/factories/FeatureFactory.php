<?php

namespace Database\Factories;

use App\Enums\FeatureStatus;
use App\Models\Feature;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => FeatureStatus::Proposed,
        ];
    }
}
