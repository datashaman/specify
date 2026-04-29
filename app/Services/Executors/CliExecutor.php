<?php

namespace App\Services\Executors;

use App\Models\Repo;
use App\Models\Task;
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

    public function execute(Task $task, ?string $workingDir, ?Repo $repo, ?string $workingBranch): array
    {
        if ($workingDir === null) {
            throw new RuntimeException('CliExecutor requires a working directory.');
        }

        $prompt = $this->buildPrompt($task, $repo, $workingBranch);

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

        return [
            'summary' => trim($stdout),
            'files_changed' => $files,
            'commit_message' => $this->commitMessageFor($task),
        ];
    }

    private function buildPrompt(Task $task, ?Repo $repo, ?string $workingBranch): string
    {
        $task->loadMissing('plan.story');
        $story = $task->plan?->story;

        $repoLine = $repo
            ? "Repository: {$repo->url} (branch: ".($workingBranch ?? $repo->default_branch).')'
            : 'Repository: (none)';

        return implode("\n", array_filter([
            $story ? "Story: {$story->name}" : null,
            "Task: {$task->name}",
            $repoLine,
            '',
            (string) $task->description,
            '',
            'Make the changes required by this Task. Stay on the working branch. Do not commit, push, or open a PR — that is handled outside.',
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

    private function commitMessageFor(Task $task): string
    {
        $name = trim((string) $task->name);

        return $name === '' ? 'specify: agent run' : 'feat: '.$name;
    }
}
