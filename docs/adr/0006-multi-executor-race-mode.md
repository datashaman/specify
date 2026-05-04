# 0006. Multi-executor race mode

Date: 2026-05-01
Status: Accepted

## Context

`Executor` (ADR-0003) is genuinely pluggable but every Subtask AgentRun has historically bound to exactly one driver chosen at config time. The marginal cost of running a second executor on the same Subtask is bounded; pre-AI, "let three engineers solve the same ticket in parallel and pick one" was absurd. With AI it isn't. The audit (Bucket 3 #1) framed the question we cannot otherwise answer: *which executor produces the best diff for which shape of Subtask?*

Two implementation shapes were considered:

- **Nested executor.** A `MultiExecutor` driver wraps N child drivers, fans out from inside `Executor::execute`, and folds the results into one `ExecutionResult`. Lifecycle gets murky — the parent AgentRun has no real diff or PR; status semantics for `markSucceeded` against a wrapper become bespoke.
- **Sibling AgentRuns.** The orchestrator (`ExecutionService`) creates *N* sibling AgentRuns when `executor.race` is non-empty — one per driver, each on its own branch, each opening its own PR. Each AgentRun stays a normal single-driver run; the cascade decides Subtask status once every sibling has terminated.

We chose siblings: orchestration belongs in the orchestrator, `Executor` stays a pure strategy, and analytics gain `executor_driver` as a load-bearing column on every Subtask run forever.

## Decision

**When `specify.executor.race` is non-empty, `ExecutionService::dispatchSubtaskExecution` creates one AgentRun per driver in the list, each on `specify/{feature}/{story}-by-{driver}`, each dispatched independently. The Subtask's status is decided by `finalizeSubtaskFromRun` only once every sibling has reached a terminal state: Done if any sibling Succeeded, Blocked if none did. The reviewer picks the winning PR by merging it; the merge state lives on the PR, not the run.**

Concrete shape:

- `agent_runs` gains a nullable `executor_driver` column. Existing rows are backfilled with `config('specify.executor.default')`. Every newly-created Subtask AgentRun stamps it — single-driver and race mode both populate it, so the field is authoritative for analytics regardless of mode.
- `config('specify.executor')` becomes `{ default, race, drivers }`. `drivers` is the single source of truth for "what drivers exist." `race` is a list of names from that map (or empty for single-driver mode). `default` is the name used when nothing else asks.
- `App\Services\Executors\ExecutorFactory::make(string $name): Executor` resolves a name through the `drivers` map. The container binding for `Executor::class` delegates to the factory using `default`. The job (`ExecuteSubtaskJob`) resolves per-run via `factory->make($run->executor_driver)`.
- The cascade gate in `finalizeSubtaskFromRun` defers when any sibling on the same Subtask is still Queued or Running. Once all siblings have terminated, it picks: any Succeeded → Done + advance; none Succeeded → Blocked.
- `PrPayloadBuilder::title($subtask, $driver)` adds a `[{driver}]` tag in race mode so reviewers can triage three near-identical PRs at a glance.

## Consequences

### Positive

- The choice the human makes between racing PRs is captured directly: `agent_runs.executor_driver` plus the merge state of each PR is the raw data for "which driver wins for which kind of Subtask."
- `Executor` implementations stay untouched. Adding a fourth driver is one entry in the `drivers` config; opting a Story into the race is one env var.
- Cascade rule is uniform: single-driver mode is "race of one" — `finalizeSubtaskFromRun` naturally collapses to the original behaviour without special-casing.

### Negative

- AI spend scales with the number of race drivers per Subtask. Mitigation: race is opt-in via env var (off by default), and N is bounded by the configured list.
- Reviewer fatigue from N near-identical PRs is real. The driver tag in the title helps; richer "diff-of-diffs" tooling is a follow-up, not blocking.
- Closed-not-merged race branches accumulate on the host repo. Mitigation: housekeeping is out of scope for V1; a webhook handler that prunes losing branches after a merge is a follow-up.

### Neutral

- `ADR-0001` (Story and current Plan are the approval gates) is preserved. The reviewer's choice of which sibling PR to merge is the same diff-review surface that already existed; race mode just gives them N options instead of one and does not add per-Task or per-Subtask approval.
- `ADR-0004` (PR after push is non-fatal) carries through unchanged — each sibling can fail to open its PR independently and record `pull_request_error` on its own AgentRun.

## Open questions (future work, not blocking)

- Should losing siblings' branches be deleted automatically after a winner is merged? (Probably yes via a host-VCS webhook; out of scope for this ADR.)
- Should a model self-judge between siblings before opening all N PRs? (Worth measuring cost first — start with "open all N, human picks.")
- Should `agent_run.race_outcome` be added as a structured signal (won/lost/tied) once a winner is known? (Yes — minimal column, big payoff for analytics — but it requires a webhook listener for merge events, so deferred.)
