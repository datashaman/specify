<?php

namespace App\Enums;

/**
 * Lifecycle of the lazy AI summary attached to a ContextItem.
 *
 * `Pending` is the initial state; `Ready` means a summary is stored;
 * `Skipped` means summarisation was bypassed (e.g. body short enough or
 * no BYOK creds); `Failed` carries the error in `summary_error`.
 */
enum ContextItemSummaryStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
