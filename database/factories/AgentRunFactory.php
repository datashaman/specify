<?php

namespace Database\Factories;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $task = Task::factory()->create();

        return [
            'runnable_type' => Task::class,
            'runnable_id' => $task->getKey(),
            'status' => AgentRunStatus::Queued,
        ];
    }
}
