# 0014. Executor contract and runtime locality

Date: 2026-05-05
Status: Accepted

Supersedes: [0003](0003-pluggable-executor-interface.md)

## Context

ADR-0003 introduced the pluggable `Executor` interface. The interface remains
the right boundary, but the implementation details changed: the in-process
Laravel AI executor now performs repo-editing work through tools, user-funded
AI calls are BYOK, and hosted deployments must enforce executor locality.

The current executor decision also incorporates later changes from:

- [ADR-0006](0006-multi-executor-race-mode.md), which runs multiple executor
  drivers against the same Subtask.
- [ADR-0011](0011-streaming-progress-events-from-executors.md), which added
  progress events to the executor call.
- [ADR-0013](0013-byok-and-executor-locality.md), which made user-owned AI
  credentials and runtime locality mandatory for hosted deployments.

## Decision

Keep one executor interface under `App\Services\Executors\Executor`:

```php
interface Executor
{
    public function needsWorkingDirectory(): bool;

    public function execute(
        Subtask $subtask,
        ?string $workingDir,
        ?Repo $repo,
        ?string $workingBranch,
        ?string $contextBrief = null,
        ?ProgressEmitter $emitter = null,
        ?string $promptOverride = null,
    ): ExecutionResult;
}
```

`needsWorkingDirectory()` tells `SubtaskRunPipeline` whether to prepare,
checkout, commit, push, and open a PR. Executors that return `false` are
describe-only; executors that return `true` are expected to inspect and mutate
the checked-out repo.

The in-tree drivers are:

- `LaravelAiExecutor` — remote-capable. It wraps `SubtaskExecutor`, gives the
  agent repo-editing tools, and resolves the run owner's BYOK provider through
  `ByokProviderResolver`.
- `CliExecutor` — local by default. It runs a configured one-shot CLI agent in
  the working directory and observes changes via git.
- `FakeExecutor` — test-only deterministic executor.

`ExecutorFactory` is the only place that resolves driver names. It reads:

- `specify.executor.default` for single-driver execution
- `specify.executor.race` for race-mode fan-out
- `specify.executor.drivers` for driver class, locality, command, and timeout
- `specify.runtime.environment` to distinguish local and hosted runtime
- `specify.runtime.remote_executors` for local drivers that an operator has
  explicitly made safe on a remote worker

Hosted runtime rejects local drivers unless the driver is listed in
`specify.runtime.remote_executors`. That list is an operator assertion; it
does not install binaries, provision credentials, or sandbox the worker.

## Consequences

Easier:

- Every execution path resolves drivers through one factory.
- Hosted deployments fail fast when a local-only driver is accidentally
  configured.
- Race mode and single-driver mode share the same validation rules.
- Laravel AI execution can run on a hosted server without an app-paid provider
  key because the AgentRun owner supplies BYOK credentials.

Harder / accepted trade-offs:

- Local CLI drivers need a separately designed remote worker before they can
  be safely used in hosted runtime.
- The executor interface is broad enough for context injection, progress
  events, and prompt overrides; new drivers must consciously handle or ignore
  those optional arguments.
- `specify.runtime.remote_executors` is configuration trust, not a security
  boundary.
