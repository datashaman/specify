<?php

namespace App\Services\Executors;

use App\Models\Repo;
use App\Models\Subtask;
use App\Services\Progress\ProgressEmitter;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Spawns an external agent CLI (claude code, codex exec, gemini -p, aider --message, …).
 * The CLI is expected to read the prompt from stdin, mutate files in its cwd, and
 * print its own log to stdout. We observe the resulting filesystem changes via git.
 */
class CliExecutor implements Executor
{
    /**
     * @param  array<int, string>  $command  Binary + static args, e.g. ['claude', '-p'] or ['codex', 'exec'].
     */
    public function __construct(
        public array $command,
        public int $timeout = 1800,
    ) {}

    public function needsWorkingDirectory(): bool
    {
        return true;
    }

    public function supportsProgressEvents(): bool
    {
        return true;
    }

    /**
     * Pipe a prompt to the CLI, run it in `$workingDir`, and report changed files.
     *
     * @throws RuntimeException When `$workingDir` is null or the CLI exits non-zero.
     */
    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
    {
        if ($workingDir === null) {
            throw new RuntimeException('CliExecutor requires a working directory.');
        }

        if ($promptOverride !== null) {
            $prompt = $promptOverride;
            if ($contextBrief !== null && $contextBrief !== '') {
                $prompt = $contextBrief."\n\n".$prompt;
            }
        } else {
            $prompt = $this->buildPrompt($subtask, $repo, $workingBranch);
            if ($contextBrief !== null && $contextBrief !== '') {
                $prompt = $contextBrief."\n\n".$prompt;
            }
        }

        $process = new Process($this->command, $workingDir);
        $process->setTimeout($this->timeout);
        $process->setInput($prompt);

        if ($emitter !== null) {
            $this->runStreaming($process, $emitter);
        } else {
            $process->run();
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'CLI executor failed (%d): %s%s%s',
                $process->getExitCode() ?? 1,
                implode(' ', $this->command),
                PHP_EOL,
                $process->getErrorOutput() ?: $process->getOutput(),
            ));
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $files = $this->changedFiles($workingDir);
        [$alreadyComplete, $alreadyCompleteEvidence] = $this->parseAlreadyCompleteSentinel($stdout);

        return new ExecutionResult(
            summary: $this->buildSummary($stdout),
            filesChanged: $files,
            commitMessage: $this->commitMessageFor($subtask),
            executorLog: $this->buildExecutorLog($stdout, $stderr),
            alreadyComplete: $alreadyComplete,
            alreadyCompleteEvidence: $alreadyCompleteEvidence,
        );
    }

    /**
     * Stream the process, emitting one stdout/stderr event per line and a
     * `sentinel` event when the ADR-0007 already-complete block flushes.
     *
     * Symfony's Process callback can be invoked mid-line on partial reads, so
     * we buffer per-stream until a newline arrives. Anything left in the
     * buffer at exit is flushed as a final event.
     */
    private function runStreaming(Process $process, ProgressEmitter $emitter): void
    {
        $buffers = ['stdout' => '', 'stderr' => ''];
        $sentinelSeen = false;

        $emit = function (string $stream, string $line) use ($emitter, &$sentinelSeen): void {
            $emitter->emit($stream, ['line' => $line]);

            if (! $sentinelSeen && $stream === 'stdout' && str_contains($line, '<<<SPECIFY:already_complete>>>')) {
                $sentinelSeen = true;
                $emitter->emit('sentinel', ['name' => 'already_complete']);
            }
        };

        $process->run(function (string $type, string $chunk) use ($emit, &$buffers): void {
            $stream = $type === Process::OUT ? 'stdout' : 'stderr';
            $buffers[$stream] .= $chunk;

            while (($nl = strpos($buffers[$stream], "\n")) !== false) {
                $line = rtrim(substr($buffers[$stream], 0, $nl), "\r");
                $buffers[$stream] = substr($buffers[$stream], $nl + 1);

                $emit($stream, $line);
            }
        });

        foreach ($buffers as $stream => $rest) {
            if ($rest !== '') {
                $emit($stream, rtrim($rest, "\r"));
            }
        }
    }

    /**
     * Detect an "already complete" sentinel block in the agent's stdout —
     * `<<<SPECIFY:already_complete>>>sha1,sha2<<<END>>>` — and return the
     * flag plus the parsed SHA list. ADR-0007.
     *
     * Free-text CLI agents that don't emit the sentinel keep the legacy
     * no-diff-as-failure path — opt-in, not magic detection.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private function parseAlreadyCompleteSentinel(string $stdout): array
    {
        if (preg_match(
            '/<<<SPECIFY:already_complete>>>(.*?)<<<END>>>/s',
            $stdout,
            $m,
        ) !== 1) {
            return [false, []];
        }

        $shas = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', $m[1]) ?: []),
            fn ($sha) => $sha !== '',
        ));

        return [true, $shas];
    }

    /**
     * Reduce the CLI's stdout to the trailing chunk a reviewer cares about.
     *
     * Agent CLIs typically print streaming progress and end with a final
     * summary; we keep the tail (≤ 4 KB at a newline boundary) so the value
     * embedded in PR bodies stays bounded. The full transcript lives on
     * `executor_log`.
     */
    private function buildSummary(string $stdout): string
    {
        $trimmed = trim($stdout);
        $limit = 4_096;
        if (strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        $tail = substr($trimmed, -$limit);
        $nl = strpos($tail, "\n");
        if ($nl !== false && $nl < $limit - 64) {
            $tail = substr($tail, $nl + 1);
        }

        return $tail;
    }

    /**
     * Capture the agent's full transcript so debugging a run does not require
     * re-running the model. Truncates at 64 KB to keep AgentRun.output bounded.
     */
    private function buildExecutorLog(string $stdout, string $stderr): ?string
    {
        $combined = trim($stdout);
        $stderr = trim($stderr);
        if ($stderr !== '') {
            $combined .= ($combined === '' ? '' : "\n\n").'--- stderr ---'."\n".$stderr;
        }
        if ($combined === '') {
            return null;
        }
        if (strlen($combined) > 65_536) {
            $combined = substr($combined, 0, 65_536)."\n[truncated]";
        }

        return $combined;
    }

    private function buildPrompt(Subtask $subtask, ?Repo $repo, ?string $workingBranch): string
    {
        $subtask->loadMissing('task.plan.story', 'task.acceptanceCriterion');
        $task = $subtask->task;
        $story = $task?->plan?->story;
        $criterion = $task?->acceptanceCriterion?->statement;

        $repoLine = $repo
            ? "Repository: {$repo->url} (branch: ".($workingBranch ?? $repo->default_branch).')'
            : 'Repository: (none)';

        return implode("\n", array_filter([
            $story ? "Story: {$story->name}" : null,
            $criterion ? "Acceptance Criterion: {$criterion}" : null,
            $task ? "Task: {$task->name}" : null,
            "Subtask: {$subtask->name}",
            $repoLine,
            '',
            (string) $subtask->description,
            '',
            'Make the changes required by this Subtask. Stay on the working branch. Do not commit, push, or open a PR — that is handled outside.',
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function changedFiles(string $workingDir): array
    {
        $process = new Process(['git', 'status', '--porcelain'], $workingDir);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $files = [];
        foreach (preg_split('/\R/', trim($process->getOutput()), flags: PREG_SPLIT_NO_EMPTY) as $line) {
            $path = trim(substr($line, 3));
            if ($path !== '') {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function commitMessageFor(Subtask $subtask): string
    {
        $name = trim((string) $subtask->name);

        return $name === '' ? 'specify: agent run' : 'feat: '.$name;
    }
}
