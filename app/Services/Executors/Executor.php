<?php

namespace App\Services\Executors;

use App\Models\Repo;
use App\Models\Task;

interface Executor
{
    /**
     * Whether this executor expects a checked-out working directory and will mutate files in it.
     * When false, the orchestrator skips clone/checkout/commit and treats the run as describe-only.
     */
    public function needsWorkingDirectory(): bool;

    /**
     * Run the executor against a Task.
     *
     * @return array{summary: string, files_changed: array<int, string>, commit_message: string}
     */
    public function execute(Task $task, ?string $workingDir, ?Repo $repo, ?string $workingBranch): array;
}
