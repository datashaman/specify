<?php

namespace App\Services\Context;

use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-subtask context brief that surfaces the Story's selected ContextItems.
 *
 * Resolves Subtask → Task → Plan → Story → includedContextItems and emits a
 * `<context-brief>`-shaped block clamped to 4 KB. Built but not wired by
 * default (see `AppServiceProvider` — only the `composite` driver enables it
 * alongside `RecencyContextBuilder`).
 */
class SelectedAssetsContextBuilder implements ContextBuilder
{
    public const MAX_BYTES = 4096;

    public function build(Subtask $subtask, ?string $workingDir, ?Repo $repo): string
    {
        try {
            $story = $subtask->task?->plan?->story;
            if ($story === null) {
                return '';
            }

            $items = $story->includedContextItems()->get();
            if ($items->isEmpty()) {
                return '';
            }

            $rendered = [];
            $usedBytes = 0;
            $dropped = 0;

            foreach ($items as $item) {
                $type = $item->type?->value ?? 'unknown';
                $body = trim($item->bodyForContext());
                $entry = "### {$item->title} ({$type})\n".($body === '' ? '(no extractable body)' : $body);
                $entryBytes = strlen($entry) + 2; // separator allowance

                if ($usedBytes + $entryBytes > self::MAX_BYTES) {
                    $dropped++;

                    continue;
                }

                $rendered[] = $entry;
                $usedBytes += $entryBytes;
            }

            if ($rendered === []) {
                return '';
            }

            $body = implode("\n\n", $rendered);
            $note = $dropped === 0
                ? ''
                : "\n\n_Truncated: {$dropped} item(s) over the ".self::MAX_BYTES.'-byte cap._';

            return "<context-brief>\n\n## Selected context assets\n\n{$body}{$note}\n\n</context-brief>";
        } catch (Throwable $e) {
            Log::warning('specify.context.selected_assets.failed', [
                'subtask_id' => $subtask->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
