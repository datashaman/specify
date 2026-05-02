<?php

namespace App\Models;

use Database\Factories\AgentRunEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * ADR-0011: append-only progress event for an AgentRun.
 *
 * Emitted by `ProgressEmitter` from inside cooperating Executors as work
 * happens (stdout line, tool call, sentinel, etc). The HTTP-poll endpoint
 * reads this table; Reverb broadcasts (Phase C) layer over the same writes.
 *
 * Append-only by contract — once written a row is never updated or deleted
 * outside the cascade from `agent_runs` (which itself never deletes).
 */
#[Fillable([
    'agent_run_id', 'seq', 'phase', 'type', 'payload', 'ts',
])]
class AgentRunEvent extends Model
{
    /** @use HasFactory<AgentRunEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new RuntimeException('Agent run events are immutable; deletion not allowed.'));
        static::updating(fn () => throw new RuntimeException('Agent run events are immutable; updates not allowed.'));
    }

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'payload' => 'array',
            'ts' => 'datetime',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
