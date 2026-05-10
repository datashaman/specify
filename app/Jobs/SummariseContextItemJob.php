<?php

namespace App\Jobs;

use App\Enums\ContextItemSummaryStatus;
use App\Models\ContextItem;
use App\Services\Ai\ContextCompressor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compresses a ContextItem's body via the BYOK-resolved summariser agent
 * and writes the outcome back to the row.
 *
 * Missing creds are not failures — they collapse to `summary_status=skipped`
 * so plan generation can fall back to the truncated raw body. Real
 * failures (provider errors, exceptions) record `summary_status=failed`.
 *
 * `summary_error` carries the human-readable note in both cases (skip
 * reason or failure cause). The status enum is the source of truth for
 * "did this succeed"; the column is just the audit trail.
 */
class SummariseContextItemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $contextItemId) {}

    public function handle(ContextCompressor $compressor): void
    {
        $item = ContextItem::query()->find($this->contextItemId);
        if ($item === null) {
            return;
        }

        $result = $compressor->summarise($item, $item->creator);

        $item->forceFill([
            'summary_status' => $result->status->value,
            'summary' => $result->status === ContextItemSummaryStatus::Ready ? $result->summary : null,
            // Persist the message for both Skipped (skip reason) and Failed
            // (failure cause). Status is the source of truth for outcome;
            // this column is the audit trail.
            'summary_error' => $result->status === ContextItemSummaryStatus::Ready ? null : $result->error,
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        $item = ContextItem::query()->find($this->contextItemId);
        if ($item === null) {
            return;
        }

        Log::error('specify.context.summarise.failed', [
            'item_id' => $item->getKey(),
            'exception' => $e?->getMessage(),
        ]);

        $item->forceFill([
            'summary_status' => ContextItemSummaryStatus::Failed->value,
            'summary_error' => $e?->getMessage() ?: 'Worker died or retries exhausted.',
        ])->save();
    }
}
