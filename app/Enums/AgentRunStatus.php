<?php

namespace App\Enums;

/**
 * Status of an AgentRun (a single dispatched plan-generation or task-execution job).
 *
 * Append-only on the AgentRun row. Cancelled = cooperative user-requested stop
 * between pipeline phases (ADR-0010); Aborted = operator force-aborted a stuck run.
 */
enum AgentRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Aborted = 'aborted';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Aborted, self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::Failed, self::Aborted, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->isActive()),
        ));
    }
}
