<?php

namespace App\Services\Executors;

use App\Models\Repo;
use App\Models\Subtask;

/**
 * Strategy that performs the engineering work for one Subtask.
 *
 * Implementations are bound by `specify.executor.driver` (see ADR-0003).
 * `LaravelAiExecutor`, `CliExecutor`, and `FakeExecutor` cover the in-tree
 * cases; new drivers register a name and implement this contract.
 */
interface Executor
{
    /**
     * Whether this executor expects a checked-out working directory and will mutate files in it.
     * When false, the orchestrator skips clone/checkout/commit and treats the run as describe-only.
     */
    public function needsWorkingDirectory(): bool;

    /**
     * Run the executor against a Subtask.
     *
     * `$contextBrief`, when non-null, is a markdown block produced by a
     * `ContextBuilder` describing files the Subtask is likely to touch,
     * recent activity in the repo, and prior failed runs. Implementations
     * should prepend it to the user prompt so the model orients faster.
     * Defaults to null so existing tests and minimal drivers remain valid.
     */
    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null): ExecutionResult;
}
