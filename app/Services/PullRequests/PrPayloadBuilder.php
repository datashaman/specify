<?php

namespace App\Services\PullRequests;

use App\Models\Subtask;

/**
 * Renders the title and body for a Subtask's pull request.
 *
 * Extracted from SubtaskRunPipeline so it can be unit-tested without spinning
 * up the queue/git/HTTP harness. The output is the human-facing surface of
 * every Specify run; keep it scannable, AC-anchored, and bounded in size.
 */
class PrPayloadBuilder
{
    /**
     * Build a scannable PR title that locates the work in the Story/AC tree.
     *
     * Reviewers triaging a queue of agent PRs benefit from the story id and
     * AC position — the bare subtask name does not place the change.
     */
    public static function title(Subtask $subtask, ?string $driver = null): string
    {
        $task = $subtask->task;
        $story = $task?->plan?->story;
        $acPos = $task?->acceptanceCriterion?->position;

        $tag = $story && $acPos !== null
            ? sprintf('[Story #%d AC#%d]', $story->getKey(), $acPos)
            : ($story ? sprintf('[Story #%d]', $story->getKey()) : '');

        // Driver tag lets reviewers triage a race-mode queue: identical
        // titles on three PRs becomes "by-cli", "by-laravel-ai", etc.
        $driverTag = $driver !== null && $driver !== '' ? '['.$driver.']' : '';
        $combined = trim($tag.$driverTag);

        return $combined !== ''
            ? sprintf('Specify %s: %s', $combined, (string) $subtask->name)
            : sprintf('Specify: %s', (string) $subtask->name);
    }

    /**
     * Render a structured PR body so reviewers see what changed, why it
     * satisfies the acceptance criterion, and which files to look at.
     *
     * The "What changed" section is defensively truncated at 8 KB to avoid
     * provider-side body-size limits when an executor emits a large summary;
     * the full transcript lives on AgentRun.output.executor_log instead.
     *
     * @param  array<string, mixed>  $output
     */
    public static function body(Subtask $subtask, array $output): string
    {
        $task = $subtask->task;
        $story = $task?->plan?->story;
        $criterion = $task?->acceptanceCriterion?->statement;
        $summary = self::clamp(trim((string) ($output['summary'] ?? '')), 8_192);
        $files = (array) ($output['files_changed'] ?? []);

        $sections = [];

        if ($story !== null) {
            $sections[] = "## Story\n".$story->name;
        }

        if ($criterion !== null && $criterion !== '') {
            $sections[] = "## Acceptance Criterion\n".$criterion;
        }

        $sections[] = "## What changed\n".($summary !== '' ? $summary : '_(no summary provided)_');

        if ($files !== []) {
            $list = implode("\n", array_map(fn ($f) => '- `'.$f.'`', $files));
            $sections[] = "## Files\n".$list;
        }

        $clarifications = (array) ($output['clarifications'] ?? []);
        if ($clarifications !== []) {
            $rendered = [];
            foreach ($clarifications as $c) {
                if (is_array($c) && isset($c['message'])) {
                    $kind = isset($c['kind']) ? '['.$c['kind'].'] ' : '';
                    $line = '- '.$kind.$c['message'];
                    if (! empty($c['proposed'])) {
                        $line .= "\n  _Proposed:_ ".$c['proposed'];
                    }
                    $rendered[] = $line;
                }
            }
            if ($rendered !== []) {
                $sections[] = "## Open questions\n".implode("\n", $rendered);
            }
        }

        $appended = (array) ($output['appended_subtasks'] ?? []);
        if ($appended !== []) {
            $rendered = [];
            foreach ($appended as $entry) {
                if (is_array($entry) && isset($entry['name'])) {
                    $rendered[] = sprintf('- #%s — %s', $entry['position'] ?? '?', $entry['name']);
                }
            }
            if ($rendered !== []) {
                $sections[] = "## Proposed follow-up subtasks\nThe executor appended these subtasks to the parent Task and they are queued to run automatically (ADR-0005). Reject the PR or request changes on the Story if the growth is wrong.\n\n".implode("\n", $rendered);
            }
        }

        $sections[] = '_Specify: human approval recorded on the Story; this PR is the diff-review surface._';

        return implode("\n\n", $sections);
    }

    /**
     * Trim a string to at most $limit bytes, preferring a newline boundary
     * near the tail and tagging the truncation explicitly.
     */
    private static function clamp(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        $tail = substr($value, -$limit);
        $nl = strpos($tail, "\n");
        if ($nl !== false && $nl < $limit - 64) {
            $tail = substr($tail, $nl + 1);
        }

        return "_(summary truncated; see AgentRun.output.executor_log for the full transcript)_\n\n".$tail;
    }
}
