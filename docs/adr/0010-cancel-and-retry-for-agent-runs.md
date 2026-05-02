# 0010. Cancel and retry semantics for AgentRuns

Date: 2026-05-02
Status: Proposed

## Context

Two affordances the UI design brief (2026-05-02 draft) explicitly defers because no domain plumbing exists for them today:

- **Cancel a running AgentRun.** `Executor::execute` (ADR-0003) returns one `ExecutionResult` at the end and has no in-process kill switch. `LaravelAiExecutor` runs synchronously inside `ExecuteSubtaskJob` with no cooperative cancel point. `CliExecutor` has a configurable timeout but no cancel plumbing — operators currently kill the queue worker or wait for the timeout.
- **Retry a Failed Subtask.** `ExecuteSubtaskJob` is dispatched per Subtask; once a run terminates Failed and the Subtask is Blocked, there's no domain affordance to dispatch another attempt without manual database surgery.

Race mode (ADR-0006) makes both questions ambiguous in a way single-driver mode does not: cancelling "the run for this Subtask" could mean one sibling or all N. Retrying could mean re-running just the failed sibling or the whole race fan-out.

A third related affordance — **retrying just the PR-creation step** for a Succeeded run that has `pull_request_error` set — is named in ADR-0004's follow-ups. It is the same family of operation (act on an existing AgentRun's artefacts) without being either cancel or retry of the run itself.

Two implementation shapes were considered for cancel:

- **Hard cancel via SIGTERM.** Works for `CliExecutor`; doesn't work for `LaravelAiExecutor` (synchronous in-process model call). Requires per-driver branching in the cancel controller. Honest about its asymmetry; partial.
- **Cooperative cancel via flag.** `agent_runs.cancel_requested` column; the pipeline polls between phases (and, where the executor cooperates, between tool calls). Doesn't kill long-running tool calls mid-flight. Honest about its partiality; works uniformly across drivers.

We chose cooperative + per-driver augmentation: the flag is the universal contract; drivers that can react more quickly do so.

## Decision

**Cancel is cooperative, retry creates a new AgentRun, and the PR-retry case becomes a third domain operation with its own job. All three are surfaced through `ExecutionService` and live alongside the existing dispatch path; none mutates an existing AgentRun's terminal state.**

Concrete shape:

### Cancel

- New column `agent_runs.cancel_requested` (boolean, default false). Set by `ExecutionService::cancelRun(AgentRun)`. The flag is write-once (set true on cancel request) and survives the terminal transition as part of the audit trail — `agent_runs` is append-only, so a reviewer querying "was this Cancelled run cancel-requested?" gets a yes that matches the row's terminal status.
- `SubtaskRunPipeline` polls the flag between phases (prepare / checkout / execute / commit / diff / push / open PR). When set, it transitions to a new terminal state `Cancelled` (Failure-class — the cascade treats it as Blocked).
- `Executor` interface gains an optional `supportsCooperativeCancel(): bool` capability (default false). Drivers that opt in receive a `CancelToken` argument on `execute()` and may check it between tool calls. `LaravelAiExecutor` opts in (the agent loop is ours); `CliExecutor` does not (CLI process is opaque). Cancel for non-cooperating drivers waits for the next pipeline-phase boundary or the configured timeout.
- New state `AgentRunStatus::Cancelled`. `SubtaskRunOutcome::cancelled()` factory; `isFailure()` true.
- **Race semantics**: `cancelRun` cancels exactly the targeted AgentRun. A `cancelSubtask(Subtask)` convenience method cancels all sibling AgentRuns for that Subtask. The UI offers both; the API is explicit.

### Retry

- Retry is **not** a state transition on the existing run; it is a **new AgentRun**. `agent_runs` is append-only (CLAUDE.md).
- New column `agent_runs.retry_of_id` (nullable FK on `agent_runs`). Populated when `ExecutionService::retrySubtaskExecution(Subtask, ?fromRun)` dispatches a fresh run for a Subtask whose previous run terminated Failed / Cancelled / Blocked.
- The new run authorises against the **same `StoryApproval`** as the retried-from run, *only if* the Story is still at the same revision. If the Story has been edited since (revision bumped → approval reset → re-approved at a new revision), the retry binds to the **current** approving `StoryApproval`. Retrying a run authorised by an approval that has been revoked or superseded by ChangesRequested fails with a clear error.
- Race mode: `retrySubtaskExecution` retries **only the targeted sibling driver** by default; an explicit `retryRace(Subtask)` retries every driver in the configured race list.
- `respond_to_review` runs (ADR-0008) are not retryable through this mechanism — they re-fire automatically when new review events arrive (subject to cycle cap).

### PR retry

- New job `OpenPullRequestJob` and corresponding `ExecutionService::retryPullRequestOpen(AgentRun)` for runs in state Succeeded with `pull_request_error` set and `pull_request_url` empty.
- Re-invokes `PullRequestManager::for($repo)?->open(...)` against the AgentRun's recorded branch and commit. On success: clears `pull_request_error`, stamps `pull_request_url` on the AgentRun's output. On failure: overwrites `pull_request_error` with the new message; AgentRun remains Succeeded (ADR-0004 preserved).
- Idempotent: if a PR already exists for the branch (returned by `PullRequestProvider::find($branch)`), adopt its URL rather than open a duplicate.
- Race siblings: each AgentRun is a separate row with its own `pull_request_error`; PR retry operates per-run.

## Consequences

### Positive

- The "kill this run" question gets an honest answer: cooperative cancel works uniformly, drivers that can react faster do, drivers that can't fall back to phase-boundary checks.
- Retry becomes a queryable history (`retry_of_id` chain) rather than a hidden re-dispatch — a reviewer can ask "how many attempts did Subtask 37 take?" and get an answer.
- ADR-0004's named follow-up (retry the PR open for a successful run) lands without any change to the run's terminal-state semantics.
- ADR-0001's invariant (approval is the only gate) stays intact: retry re-resolves authorisation through the current `StoryApproval`, never bypasses it.

### Negative

- `CliExecutor` cancel latency is bounded only by the next phase boundary (typically the executor's full timeout). Mitigation: the timeout is the failsafe; truly stuck CLI runs require operator intervention. A future driver-specific cancel (signal the process group) is a follow-up, not blocking.
- `cancel_requested` and `retry_of_id` are two new columns on `agent_runs`. Schema growth is cheap but adds to the surface area of the append-only invariant — both columns are write-once at dispatch / cancel-request time, never mutated thereafter.
- PR retry can re-introduce ordering races if invoked while the original `open()` call is still in flight on a slow API. Mitigation: a `Cache::lock` keyed by `agent_run_id` serialises the retry against any concurrent PR open attempt.

### Neutral

- ADR-0003 is amended in place to note the optional `supportsCooperativeCancel()` capability. Existing executors continue to work without it.
- ADR-0006 (race mode) is preserved: cancel and retry both operate per-AgentRun; sibling cancel/retry are explicit conveniences, not hidden cascades.
- ADR-0008 (respond-to-review) is preserved: review-response runs are not subject to manual retry.

## Open questions

- Should `Cancelled` runs count toward the race-mode cascade gate (`finalizeSubtaskFromRun`)? Lean: yes — Cancelled is a Failure-class terminal state; the cascade treats it the same as Failed for the "no sibling Succeeded → Blocked" rule.
- Should retry be exposed as an MCP tool, or only as a UI button? Probably both, but the MCP tool wants its own surface (input validation, audit log entry).
- A "retry from phase N" affordance (skip prepare/checkout if branch already exists, resume from commit) would speed retries but couples the retry semantics to the pipeline's internals. Defer; for V1, retry replays the whole pipeline.
