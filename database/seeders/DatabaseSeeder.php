<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Top-level seeder. Creates a usable demo workspace/project with stories, scenarios, plans, tasks, and subtasks. */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DemoDataSeeder::class);
    }
}
