<?php

namespace App\Services\Executors;

use App\Models\Repo;
use App\Models\Subtask;

interface Executor
{
    /**
     * Whether this executor expects a checked-out working directory and will mutate files in it.
     * When false, the orchestrator skips clone/checkout/commit and treats the run as describe-only.
     */
    public function needsWorkingDirectory(): bool;

    /**
     * Run the executor against a Subtask.
     */
    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch): ExecutionResult;
}
