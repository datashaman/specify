<?php

namespace Database\Factories;

use App\Models\ContextItem;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContextItem>
 */
class ContextItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => fake()->randomElement(['document', 'link', 'note']),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'metadata' => [],
        ];
    }
}
