<?php

namespace App\Enums;

/**
 * Status of an AgentRun (a single dispatched plan-generation or task-execution job).
 *
 * Append-only on the AgentRun row; failures move to Failed, manual cancels to Aborted.
 */
enum AgentRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Aborted = 'aborted';
}
