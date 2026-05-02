<?php

namespace App\Services\Executors;

use App\Models\Repo;
use App\Models\Subtask;
use App\Services\Progress\ProgressEmitter;

/**
 * Strategy that performs the engineering work for one Subtask.
 *
 * Implementations are registered in `specify.executor.drivers` and resolved
 * by name via `ExecutorFactory` (see ADR-0003).
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
     * Capability flag (ADR-0011). When true, the pipeline constructs a
     * `ProgressEmitter` for the run and passes it to `execute()`; the
     * driver emits typed events as work happens. Drivers that return
     * false receive `null` and do nothing differently — backwards
     * compatibility for existing impls.
     */
    public function supportsProgressEvents(): bool;

    /**
     * Run the executor against a Subtask.
     *
     * `$contextBrief`, when non-null, is a markdown block produced by a
     * `ContextBuilder` describing files the Subtask is likely to touch,
     * recent activity in the repo, and prior failed runs. Implementations
     * should prepend it to the user prompt so the model orients faster.
     * Defaults to null so existing tests and minimal drivers remain valid.
     *
     * `$emitter`, when non-null (only passed when `supportsProgressEvents()`
     * is true — ADR-0011), is the per-run progress channel. Drivers call
     * `$emitter->emit($type, $payload)` from within tool-call hooks /
     * stdout handlers / sentinel parsers to surface live events.
     */
    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null): ExecutionResult;
}
