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

Use [`0000-template.md`](0000-template.md) when adding a new ADR. Number sequentially and update this index.
