# 0003. Pluggable Executor interface

Date: 2026-05-01
Status: Accepted

## Context

Specify needs to delegate "do the engineering work for this Subtask" to something — sometimes a hosted Anthropic model via `laravel/ai`, sometimes a one-shot CLI agent (Claude Code, codex, gemini, aider) the operator already trusts in their own toolchain, sometimes a fake for tests. Hard-coding any one of these into `ExecuteSubtaskJob` would either lock the project to a single AI vendor or force every test to spin up a real model.

We also wanted the choice of executor to be a deployment concern, not a code concern: rotating from one CLI to another should be a config edit, not a refactor.

## Decision

Define an `Executor` interface with a minimal contract:

```php
interface Executor
{
    public function needsWorkingDirectory(): bool;
    public function execute(
        Subtask $subtask,
        ?string $workingDir,
        ?Repo $repo,
        ?string $workingBranch,
    ): ExecutionResult;
}
```

Three implementations live under `app/Services/Executors/`:

- `LaravelAiExecutor` — describe-only; wraps the `TaskExecutor` agent and returns a structured plan without mutating the filesystem. Used when the executor is producing instructions, not edits.
- `CliExecutor` — generic; runs any one-shot agent CLI with the working directory as cwd and observes results via `git status`. Configured via `specify.executor.cli.{command, timeout}`.
- `FakeExecutor` — test double producing deterministic `ExecutionResult`s.

The active executor is bound by `specify.executor.driver`. `SubtaskRunPipeline` resolves it once per run.

## Consequences

Easier:
- New executors (e.g. provider-specific CLIs, in-process agents) drop in by implementing the interface and registering a driver name.
- Tests bind `FakeExecutor` and run the full pipeline without any model call.
- Operators can switch from a hosted model to a CLI without redeploying code, by changing one config key.

Harder / accepted trade-offs:
- The interface treats `git status` observation as the universal "what changed" signal. Executors that mutate state outside the workdir aren't representable.
- Vendor-specific affordances (streaming output, partial results, mid-run prompts) aren't in the interface; adding them would require a wider contract or per-driver capability flags.

Follow-ups:
- Capability flags (e.g. "supports streaming", "supports interactive clarification") if a future driver needs to expose richer behaviour to the pipeline.
