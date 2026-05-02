# 0011. Streaming progress events from executors

Date: 2026-05-02
Status: Proposed

## Context

The UI design brief (2026-05-02 draft) ships log streaming via HTTP polling on `runs/{id}/logs?after={cursor}` at 1–2s intervals. That works because it requires no executor changes, but it has two known limits:

- **Latency floor.** The fastest a watcher sees a tool call is "next poll cycle." For runs that produce 10+ tool calls per second (large refactors), the user sees jumpy bursts of work, not a live console.
- **Cost.** Every connected watcher polls; a Story page with N watchers and a long-running run is N requests per second against the runs endpoint, even when nothing has happened.

The honest fix is to push events from the executor as they happen. ADR-0003's follow-ups list capability flags exactly for this case ("supports streaming, supports interactive clarification") — the design grill confirmed they're load-bearing for a real-time console.

Two implementation shapes were considered:

- **Tail the agent_run row.** Executor writes log lines to a `agent_run_events` table; the controller exposes a Reverb-bridged stream. Decouples the executor from broadcasting but doubles writes (DB + broadcast); growing event log inflates run row size.
- **Broadcast directly from the executor.** Executor calls `broadcast()` per event; transport layer is Reverb private channels. No extra storage; events are ephemeral (a watcher who joins late sees no history beyond what's in the polling endpoint).

We chose **broadcast-direct with HTTP-poll fallback** for late joiners. The poll endpoint stays as the source of truth for "what happened in this run"; the broadcast is the low-latency optimisation for connected watchers.

## Decision

**The `Executor` interface gains an optional `ProgressEmitter` argument and a `supportsProgressEvents(): bool` capability. Drivers that opt in emit structured events as they make tool calls; the pipeline persists each event to `agent_run_events` and broadcasts it on `runs.{agent_run_id}.log`. The HTTP-poll endpoint reads from `agent_run_events`; Reverb watchers receive the events live.**

Concrete shape:

- New table `agent_run_events`:
  - `id`, `agent_run_id` FK, `seq` (per-run monotonic), `ts`, `type` (`tool_call|edit|shell|thinking|stdout|stderr|error|sentinel`), `payload` (JSON).
  - Unique index on `(agent_run_id, seq)`.
  - **Append-only**; never updated.
- `Executor::execute()` signature extends to accept an optional `ProgressEmitter $emitter`:
  ```php
  public function execute(
      Subtask $subtask,
      ?string $workingDir,
      ?Repo $repo,
      ?string $workingBranch,
      ?ProgressEmitter $emitter = null,
  ): ExecutionResult;
  ```
  Drivers that don't implement progress events ignore the argument; existing executors compile unchanged.
- `ProgressEmitter` is a thin wrapper around the run's id and a sequence counter. Calls to `emit($type, $payload)` write a row to `agent_run_events` and broadcast on `runs.{id}.log`. Writes are batched per pipeline phase (typed buffer, flushed every 250ms or on phase boundary) to keep DB churn bounded.
- New capability flag `Executor::supportsProgressEvents(): bool` (default false). The pipeline reads it once at run start and only constructs an emitter for cooperating drivers.
- **`LaravelAiExecutor`** opts in. Tool-call lifecycle hooks emit `tool_call`, `edit`, `shell`, and `thinking` events; the agent loop already has the introspection points.
- **`CliExecutor`** opts in for stdout/stderr only. The process pipe is read line-buffered and emitted as `stdout` / `stderr` events; sentinel-block parsing (ADR-0007 `<<<SPECIFY:already_complete>>>`; future: `clarifications`) is detected at emit time and tagged as `sentinel`. Tool-call introspection is not available for opaque CLIs.
- **`FakeExecutor`** opts in with synthetic events for tests.
- HTTP-poll endpoint `runs/{id}/logs?after={cursor}` reads from `agent_run_events` (`WHERE seq > cursor ORDER BY seq`). Polling and Reverb both read the same table; the broadcast is the live optimisation.
- The runs page's existing log rendering (Slice 2 of the UI brief) is unchanged: it already consumes the polling endpoint. Reverb support is layered on; on disconnect, the Volt component falls back to polling.

The interface change to `Executor::execute` is the only ADR-0003 amendment this ADR introduces. Capability flag preserves backwards compatibility — non-cooperating drivers do nothing differently and have no rows in `agent_run_events`.

## Consequences

### Positive

- Latency floor drops from "next poll" to "next broadcast" — typically <100ms for connected watchers.
- The HTTP-poll endpoint remains authoritative; late joiners and disconnected watchers reconstruct full history from `agent_run_events`. No "you missed it" gaps.
- The flame-chart Timeline tab (UI brief Slice 3, structured drivers only) gains its data source for free — `agent_run_events` rows of type `tool_call` already encode the start/end timestamps it needs.
- Sentinel parsing for CLI runs becomes a first-class typed event rather than a UI-side string match.

### Negative

- **Database write amplification.** A run that previously produced 1 row in `agent_runs` now produces N rows in `agent_run_events`. Mitigation: 250ms emitter batching; partition or prune `agent_run_events` older than M days (config knob) once the table grows.
- **Reverb fanout cost** scales with watchers per run. Mitigation: events are private-channel; only authorised watchers join. For pathologically chatty runs, the batching window absorbs most of the cost.
- **Broadcast/persist ordering**: events must persist before broadcast (otherwise a Reverb-only watcher could see an event that's not in the poll endpoint). Mitigation: emitter writes the row, then broadcasts; on broadcast failure, the row stays — eventual consistency holds via polling.

### Neutral

- ADR-0001 / ADR-0008: unchanged. Streaming is a transport concern; approval and review-response semantics don't move.
- ADR-0003: amended in place to record the optional `ProgressEmitter` argument and the `supportsProgressEvents` capability. Existing drivers compile unchanged.
- ADR-0007: the `<<<SPECIFY:already_complete>>>` sentinel becomes a typed `sentinel` event in addition to its end-of-run parse. Both paths converge on the same evidence list.

## Open questions

- Should `agent_run_events` carry a `phase` column (prepare / execute / commit / push / etc.) for filtering, or is `type` enough? Lean: yes, add `phase`; cheap, useful for the Timeline view.
- Retention: how long do we keep events for terminated runs? Lean: 30 days default, configurable; long enough for review-response cycles to matter.
- Should the broadcast carry the full payload or just `(seq, type)` with a refetch from the endpoint? Lean: full payload — Reverb message size limits are generous and refetch defeats the latency win.
- Future drivers that emit token-level events (e.g. streaming model output as it generates) need a per-token `thinking` rate that may overwhelm the 250ms batch window. Cap or sample at emit time? Defer until a driver actually does this.
