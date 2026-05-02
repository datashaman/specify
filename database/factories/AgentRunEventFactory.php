<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRunEvent>
 */
class AgentRunEventFactory extends Factory
{
    protected $model = AgentRunEvent::class;

    public function definition(): array
    {
        return [
            'agent_run_id' => AgentRun::factory(),
            'seq' => 1,
            'phase' => 'execute',
            'type' => 'stdout',
            'payload' => ['line' => 'fake'],
            'ts' => now(),
        ];
    }
}
