<?php

namespace App\Models;

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Append-only record of one AI dispatch — task generation or subtask execution.
 *
 * `runnable` is polymorphic over Story (for task generation) and Subtask
 * (for execution). `authorizing_approval` ties the run back to the
 * StoryApproval that authorised it. Stores the agent's input, output, diff,
 * token usage, and timing for audit and replay.
 *
 * `kind` distinguishes the historical `Execute` run (produces the Subtask
 * diff and opens a PR) from `RespondToReview` (ADR-0008 — pushes a
 * `fix(review):` commit on the originating Subtask's open PR) and
 * `ResolveConflicts` (human-triggered merge-conflict repair on the primary
 * PR). The cascade gate ignores RespondToReview and ResolveConflicts runs.
 */
#[Fillable([
    'runnable_type', 'runnable_id',
    'repo_id', 'working_branch', 'executor_driver', 'kind',
    'authorizing_approval_type', 'authorizing_approval_id',
    'status', 'agent_name', 'model_id',
    'input', 'output', 'diff', 'error_message',
    'tokens_input', 'tokens_output',
    'started_at', 'finished_at',
    'cancel_requested', 'retry_of_id',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new RuntimeException('Agent runs are immutable; deletion not allowed.'));
    }

    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'kind' => AgentRunKind::class,
            'input' => 'array',
            'output' => 'array',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested' => 'boolean',
        ];
    }

    public function runnable(): MorphTo
    {
        return $this->morphTo();
    }

    public function authorizingApproval(): MorphTo
    {
        return $this->morphTo();
    }

    public function repo(): BelongsTo
    {
        return $this->belongsTo(Repo::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AgentRunStatus::activeValues());
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }
}
