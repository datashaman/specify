<?php

namespace App\Enums;

enum AgentRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Aborted = 'aborted';
}
