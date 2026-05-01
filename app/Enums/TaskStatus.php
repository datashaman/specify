<?php

namespace App\Enums;

/**
 * Lifecycle of a Task during execution.
 *
 * Pending → InProgress → Done is the happy path; Blocked is set when
 * an unmet dependency or external failure halts progress.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Blocked = 'blocked';
}
