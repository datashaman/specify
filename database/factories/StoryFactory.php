<?php

namespace Database\Factories;

use App\Enums\StoryKind;
use App\Enums\StoryStatus;
use App\Models\Feature;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feature_id' => Feature::factory(),
            'name' => fake()->sentence(4),
            'kind' => StoryKind::UserStory,
            'actor' => fake()->jobTitle(),
            'intent' => fake()->sentence(6),
            'outcome' => fake()->sentence(8),
            'description' => fake()->paragraph(),
            'status' => StoryStatus::Draft,
        ];
    }
}
