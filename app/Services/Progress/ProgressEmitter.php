<?php

namespace App\Services\Progress;

use App\Models\AgentRun;
use App\Models\AgentRunEvent;

/**
 * ADR-0011: writes one progress-event row per `emit()` call against an
 * AgentRun. Keeps the per-run sequence counter in memory so the
 * pipeline's typical "executor → emit → emit → emit" hot path doesn't
 * round-trip to the DB for the next-seq lookup. The first emit on a run
 * primes the counter from MAX(seq).
 *
 * Phase A scope: synchronous DB writes only. Reverb broadcast (Phase C)
 * and 250ms batching (ADR-0011 mitigation for write amplification) layer
 * on top of this surface; both wrap `emit()` rather than replace it.
 */
class ProgressEmitter
{
    private int $seq = 0;

    private bool $primed = false;

    private string $phase = 'execute';

    public function __construct(public readonly AgentRun $agentRun) {}

    /**
     * Set the pipeline phase that subsequent events are tagged with.
     * Called by SubtaskRunPipeline at phase boundaries; executors don't
     * touch this directly.
     */
    public function setPhase(string $phase): void
    {
        $this->phase = $phase;
    }

    /**
     * Persist one event. Returns the new AgentRunEvent so callers can
     * inspect seq / id (used by the broadcast layer in Phase C).
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $type, array $payload = []): AgentRunEvent
    {
        if (! $this->primed) {
            $this->seq = (int) AgentRunEvent::query()
                ->where('agent_run_id', $this->agentRun->getKey())
                ->max('seq');
            $this->primed = true;
        }

        $this->seq++;

        return AgentRunEvent::create([
            'agent_run_id' => $this->agentRun->getKey(),
            'seq' => $this->seq,
            'phase' => $this->phase,
            'type' => $type,
            'payload' => $payload,
            'ts' => now(),
        ]);
    }
}
