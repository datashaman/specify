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
    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch): ExecutionResult
    {
        if ($workingDir === null) {
            throw new RuntimeException('CliExecutor requires a working directory.');
        }

        $prompt = $this->buildPrompt($subtask, $repo, $workingBranch);

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
        $files = $this->changedFiles($workingDir);

        return new ExecutionResult(
            summary: trim($stdout),
            filesChanged: $files,
            commitMessage: $this->commitMessageFor($subtask),
        );
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
