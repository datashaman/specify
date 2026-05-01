# Proposal 0005 — Bucket 1 kill-list

Status: Draft (items 3–5 implemented on this branch; items 1–2 deferred as RFC)
Date: 2026-05-01
Source: AI strategy audit, Bucket 1

## Premise

Five Bucket-1 findings from the AI strategy audit. Three are tractable as code edits in one PR; two are larger redesigns that need their own ADRs. This document covers all five so the kill-list is not lost.

## Items implemented on this branch

### #3 — PR title/body should answer the AC, not echo the subtask name

**Before**: every PR's title is `'Specify: '.$subtask->name`; the body is the raw `summary` field from the executor's structured output.

**After**: the body is rendered as a structured markdown block:

```
## Acceptance Criterion
{ac.text}

## What changed
{summary}

## Files
- {file 1}
- ...

## Open questions
{clarifications, if any — Proposal 0004}
```

Title remains short but adds the AC position so reviewers can scan a queue: `'Specify [Story #{id} AC#{pos}]: '.$subtask->name`.

**File**: `app/Services/SubtaskRunPipeline.php`.

### #4 — `CliExecutor` should preserve the agent's stdout

**Before**: stdout was returned as the `summary` of the `ExecutionResult`, but anything beyond the trimmed final output was thrown away. Tool calls, retries, and "I tried X but it failed" notes are lost.

**After**: full stdout (and stderr if non-empty) is captured and persisted on `AgentRun.output['executor_log']` (truncated at 64 KB). The `summary` becomes the *last* paragraph of stdout (best-effort), not the entire blob.

**File**: `app/Services/Executors/CliExecutor.php` and `app/Services/SubtaskRunPipeline.php`.

### #5 (partial) — fix `PlanGenerator` ghost reference in README

**Before**: README says "GenerateTasksJob → PlanGenerator agent" but the actual class is `TasksGenerator`.

**After**: README says "GenerateTasksJob → TasksGenerator agent". One-line doc fix; not an API change.

**File**: `README.md`.

The MCP tool slug rename (`generate-plan` → `generate-tasks`) is **deferred** because it's a public-API break for any MCP client. See "Deferred — needs an ADR" below.

## Deferred — needs an ADR

### #1 — Up-front plan as a frozen artefact

This is the largest finding from the audit. The current shape (TasksGenerator runs once per Story; edits reset approval) is a deliberate, ADR-documented design — replacing it requires a counter-ADR, not a kill-list edit.

Sketch of the redesign worth proposing in a follow-up ADR:

- **Plan as running hypothesis.** Generate the task list lazily on the first Subtask execution, not eagerly on Story approval. Re-generate at the start of each subsequent Subtask using the current repo state.
- **Mid-run plan amendment.** Let `SubtaskExecutor` propose new Subtasks (via a new clarification kind, building on Proposal 0004). Append-only — never edits prior Subtasks.
- **Story re-approval as notification, not blocker.** When the plan grows mid-run, notify the approver but don't halt the run. The Story-as-only-gate property is preserved (ADR-0001) because the *initial* approval still authorises the work; growth is incremental.

This is a real ADR, not a kill-list line. Recommend opening it as `ADR-0005-running-hypothesis-plans.md` with explicit reference to ADR-0001's invariants.

### #2 — Subtasks run linearly with no agent feedback path

Same shape as #1: the linear pipeline is a deliberate design that an ADR should redesign, not a code edit. The voice-for-the-executor work in Proposal 0004 is the smallest first step that opens this door without contradicting ADR-0001.

### #5 (full) — Rename `generate-plan` MCP tool to `generate-tasks`

**Why deferred**: every MCP client that currently calls `generate-plan` would break. Need to:

1. Add `generate-tasks` as an alias that forwards to the same handler.
2. Update internal docs and clients.
3. Deprecate `generate-plan` with a console warning for one minor version.
4. Remove `generate-plan` after the deprecation window.

Recommend opening as a separate small PR with the alias-and-deprecate dance. ADR-0002 already promised this with a deprecation period.

## Risk and reversibility

The three implemented items are all reversible and low-blast-radius:

- The PR body change reverts to a one-liner with one commit.
- The CLI executor stdout passthrough is additive — no removed fields.
- The README fix is a doc-only change.

The three deferred items are intentionally not implemented here. Each gets its own ADR or follow-up PR.
