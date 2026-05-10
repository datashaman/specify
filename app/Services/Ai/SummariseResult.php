<?php

namespace App\Services\Ai;

use App\Enums\ContextItemSummaryStatus;

/**
 * Outcome of a single ContextCompressor::summarise() call. Pure value
 * object — the job is responsible for writing the result back to the
 * ContextItem row.
 */
class SummariseResult
{
    public function __construct(
        public ContextItemSummaryStatus $status,
        public ?string $summary = null,
        public ?string $error = null,
    ) {}

    public static function ready(string $summary): self
    {
        return new self(ContextItemSummaryStatus::Ready, $summary);
    }

    public static function skipped(?string $reason = null): self
    {
        return new self(ContextItemSummaryStatus::Skipped, error: $reason);
    }

    public static function failed(string $error): self
    {
        return new self(ContextItemSummaryStatus::Failed, error: $error);
    }
}
