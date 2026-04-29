<?php

namespace Database\Factories;

use App\Enums\RepoProvider;
use App\Models\Repo;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repo>
 */
class RepoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'provider' => RepoProvider::Github,
            'url' => 'https://github.com/example/'.$name.'.git',
            'default_branch' => 'main',
        ];
    }
}
