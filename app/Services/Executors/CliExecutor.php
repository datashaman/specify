<?php

namespace App\Services\Executors;

use App\Models\Repo;
use App\Models\Subtask;
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

    /**
     * Pipe a prompt to the CLI, run it in `$workingDir`, and report changed files.
     *
     * @throws RuntimeException When `$workingDir` is null or the CLI exits non-zero.
     */
    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null): ExecutionResult
    {
        if ($workingDir === null) {
            throw new RuntimeException('CliExecutor requires a working directory.');
        }

        $prompt = $this->buildPrompt($subtask, $repo, $workingBranch);
        if ($contextBrief !== null && $contextBrief !== '') {
            $prompt = $contextBrief."\n\n".$prompt;
        }

        $process = new Process($this->command, $workingDir);
        $process->setTimeout($this->timeout);
        $process->setInput($prompt);
        $process->run();

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

        return new ExecutionResult(
            summary: $this->buildSummary($stdout),
            filesChanged: $files,
            commitMessage: $this->commitMessageFor($subtask),
            executorLog: $this->buildExecutorLog($stdout, $stderr),
        );
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
        $subtask->loadMissing('task.story', 'task.acceptanceCriterion');
        $task = $subtask->task;
        $story = $task?->story;
        $criterion = $task?->acceptanceCriterion?->criterion;

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
