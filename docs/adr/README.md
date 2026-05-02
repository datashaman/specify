# Architecture Decision Records

Short, append-only records of the load-bearing decisions in this codebase.
Format follows [adr-tools](https://github.com/npryce/adr-tools) lite: one
file per decision, monotonically numbered, never edited after Acceptance
except to mark them Superseded.

| # | Title | Status |
|---|-------|--------|
| [0001](0001-story-as-the-only-approval-gate.md) | Story is the only approval gate | Accepted |
| [0002](0002-story-task-subtask-hierarchy.md) | Story → Task → Subtask hierarchy (Plan retired) | Accepted |
| [0003](0003-pluggable-executor-interface.md) | Pluggable Executor interface | Accepted |
| [0004](0004-pr-after-push-is-non-fatal.md) | Opening the pull request after push is a non-fatal step | Accepted |
| [0005](0005-plans-grow-append-only-mid-run.md) | Plans grow append-only mid-run | Accepted |
| [0006](0006-multi-executor-race-mode.md) | Multi-executor race mode | Accepted |
| [0007](0007-already-complete-subtasks.md) | Already-complete subtasks | Accepted |
| [0008](0008-pr-review-feedback-via-webhooks.md) | PR review feedback closes the loop via webhooks | Accepted |
| [0009](0009-story-snapshots-for-run-authorisation.md) | Story snapshots for run authorisation | Proposed |
| [0010](0010-cancel-and-retry-for-agent-runs.md) | Cancel and retry semantics for AgentRuns | Proposed |
| [0011](0011-streaming-progress-events-from-executors.md) | Streaming progress events from executors | Proposed |
| [0012](0012-project-first-information-architecture.md) | Project-first information architecture | Proposed |

Use [`0000-template.md`](0000-template.md) when adding a new ADR. Number sequentially and update this index.
