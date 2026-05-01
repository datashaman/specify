<?php

namespace App\Services\Context;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Three cheap signals layered into a markdown brief:
 *
 *   1. Files the Subtask description mentions by path (verified to exist).
 *   2. Files recently touched in the working directory's git history,
 *      intersected with that mentioned set.
 *   3. Prior `AgentRun`s for this Subtask that ended in `Failed` (which
 *      includes the `noDiff` and `pullRequestFailed` outcomes — see
 *      `ExecuteSubtaskJob` for the outcome → status mapping). The
 *      executor can learn from its own history.
 *
 * Output is capped at 4 KB. Failures are non-fatal: a builder should never
 * tank a run, so any exception logs and returns an empty brief.
 */
class RecencyContextBuilder implements ContextBuilder
{
    public function __construct(
        public string $window = '30.days',
        public int $maxFiles = 10,
    ) {}

    public function build(Subtask $subtask, ?string $workingDir, ?Repo $repo): string
    {
        try {
            $sections = [];

            $mentioned = $this->mentionedFiles($subtask, $workingDir);
            if ($mentioned !== []) {
                $sections[] = "## Files the subtask description mentions\n".implode("\n", array_map(
                    fn ($f) => '- `'.$f.'`',
                    $mentioned,
                ));
            }

            $recent = $this->recentlyTouched($workingDir, $mentioned);
            if ($recent !== []) {
                $rendered = [];
                foreach ($recent as $file => $note) {
                    $rendered[] = '- `'.$file.'` — '.$note;
                }
                $sections[] = "## Recently touched (last {$this->window})\n".implode("\n", $rendered);
            }

            $priorRuns = $this->priorRunSummaries($subtask);
            if ($priorRuns !== []) {
                $sections[] = "## Prior runs on this Subtask that did not complete\n".implode("\n\n", $priorRuns);
            }

            if ($sections === []) {
                return '';
            }

            $brief = "<context-brief>\n\n".implode("\n\n", $sections)."\n\n</context-brief>";

            return $this->clamp($brief, 4_096);
        } catch (Throwable $e) {
            Log::warning('specify.context.builder.failed', [
                'subtask_id' => $subtask->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Path-like tokens in the Subtask description that exist on disk.
     *
     * @return list<string>
     */
    private function mentionedFiles(Subtask $subtask, ?string $workingDir): array
    {
        $description = (string) $subtask->description;
        if ($description === '' || $workingDir === null) {
            return [];
        }

        if (! preg_match_all('#\b([A-Za-z0-9_./\\-]+\.[A-Za-z0-9]+)\b#', $description, $matches)) {
            return [];
        }

        $candidates = array_unique($matches[1]);
        $existing = [];
        foreach ($candidates as $rel) {
            if (str_contains($rel, '..')) {
                continue;
            }
            $abs = $workingDir.DIRECTORY_SEPARATOR.$rel;
            if (is_file($abs)) {
                $existing[] = $rel;
            }
        }

        return array_slice($existing, 0, $this->maxFiles);
    }

    /**
     * For each mentioned file, record the most recent commit subject from
     * the configured window. Single `git log` invocation across all files
     * with a strict overall timeout — keeps the builder inside the ≤ 1s
     * wall-clock budget the interface promises.
     *
     * @param  list<string>  $mentioned
     * @return array<string, string> file path => one-line note
     */
    private function recentlyTouched(?string $workingDir, array $mentioned): array
    {
        if ($workingDir === null || $mentioned === [] || ! is_dir($workingDir.'/.git')) {
            return [];
        }

        $files = array_values(array_slice($mentioned, 0, $this->maxFiles));
        $marker = '__RECENCY_COMMIT__ ';

        $proc = new Process([
            'git', 'log',
            '--since='.$this->window,
            '--pretty=format:'.$marker.'%h %s',
            '--name-only',
            '--',
            ...$files,
        ], $workingDir);
        $proc->setTimeout(2);

        try {
            $proc->run();
        } catch (Throwable) {
            return [];
        }

        $wanted = array_fill_keys($files, true);
        $out = [];
        $currentCommit = null;

        foreach (preg_split('/\R/', (string) $proc->getOutput()) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, $marker)) {
                $currentCommit = substr($line, strlen($marker));

                continue;
            }

            if ($currentCommit !== null && isset($wanted[$line]) && ! isset($out[$line])) {
                $out[$line] = $currentCommit;
                if (count($out) >= count($files)) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Summaries from prior AgentRuns on this Subtask that ended in a state
     * the executor can learn from (Failed). Capped at 3 entries.
     *
     * @return list<string>
     */
    private function priorRunSummaries(Subtask $subtask): array
    {
        $runs = AgentRun::query()
            ->where('runnable_type', $subtask->getMorphClass())
            ->where('runnable_id', $subtask->getKey())
            ->where('status', AgentRunStatus::Failed->value)
            ->latest('id')
            ->limit(3)
            ->get(['id', 'status', 'error_message', 'output']);

        $rendered = [];
        foreach ($runs as $run) {
            $error = trim((string) ($run->error_message ?? ''));
            $summary = '';
            if (is_array($run->output) && isset($run->output['summary'])) {
                $summary = trim((string) $run->output['summary']);
            }
            $body = $error !== '' ? "Error: {$error}" : '';
            if ($summary !== '') {
                $body .= ($body === '' ? '' : "\n").'Summary: '.$summary;
            }
            if ($body === '') {
                continue;
            }
            $rendered[] = "Run #{$run->id} — {$run->status->value}\n{$body}";
        }

        return $rendered;
    }

    private function clamp(string $value, int $limit): string
    {
        $suffix = "\n[brief truncated]";

        if (strlen($value) <= $limit) {
            return $value;
        }

        if ($limit <= strlen($suffix)) {
            return substr($suffix, 0, $limit);
        }

        return substr($value, 0, $limit - strlen($suffix)).$suffix;
    }
}
