<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0011: append-only stream of progress events from executors.
 *
 * Each row is one observable thing the run did at a point in time —
 * stdout/stderr line, tool call, thinking marker, sentinel block. The
 * HTTP-poll endpoint reads from this table; Reverb broadcasts (Phase C
 * follow-up) layer over the same row writes for live latency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_run_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();

            // Per-run monotonic sequence — the cursor the HTTP poll
            // endpoint advances. The unique index on (run, seq) is the
            // contract: clients refetching after=cursor get every event
            // they haven't already seen, in order.
            $t->unsignedInteger('seq');

            // Pipeline phase the event was emitted in. Lets the future
            // Timeline view filter by phase without reparsing payload.
            $t->string('phase', 32)->nullable();

            // tool_call | edit | shell | thinking | stdout | stderr |
            // error | sentinel — the ADR-0011 type set; new types are
            // additive (no enum cast — string keeps drivers free to
            // emit ADR-driven types without a schema migration).
            $t->string('type', 32);

            $t->json('payload');
            $t->timestamp('ts')->useCurrent();

            $t->unique(['agent_run_id', 'seq']);
            $t->index(['agent_run_id', 'ts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_events');
    }
};
