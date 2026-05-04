# 0009. Story snapshots for run authorisation

Date: 2026-05-02
Status: Proposed

## Context

`Story` and `Plan` carry integer revisions that auto-bump on edits (ADR-0001/0002), and `AgentRun.authorizing_approval` points at the immutable approval row that sanctioned the run: `StoryApproval` for task-generation runs, `PlanApproval` for execution runs. Together they tell us *that* a run was authorised — but not *what was authorised*. The Story body, acceptance criteria, scenarios, current Plan, Tasks, and Subtasks are mutable. Once a Story or Plan moves from v7 to v8, there is no way to render "what v7 looked like."

This is fine for the cascade and the executor — they read the current state when dispatched. It is not fine for two consumers we now want to support:

- **The UI design brief (2026-05-02 draft)** specifies a "Story has changed since this run started — view diff" affordance on the Run console header. With no snapshot, the UI cannot honestly show the v7-at-dispatch-time state alongside the v8 current state.
- **Audit and post-hoc review.** A reviewer asking "what did I actually approve at 14:02 on 2026-05-01?" today has to read commit history, git branches, and the diff on the open PR. The structured story body and plan as approved are not recoverable.

We considered three paths during the design grill:

1. **Display only the integer revision.** Cheap; loses the audit trail; the UI cannot honestly visualise drift. (Chosen for V1 of the UI brief.)
2. **Synthesise from approval rows.** Re-derive a snapshot when revisions match; show "approval record only" otherwise. Lossy and inconsistent.
3. **Add a real snapshot table.** Hard-to-reverse schema change; full audit trail; UI can render any historical revision verbatim.

V1 ships path 1. This ADR is the schema change that unblocks the drift indicator and a richer audit surface in a later slice.

## Decision

**On every successful Story or Plan approval transition (`PendingApproval -> Approved`), `ApprovalService` writes an append-only snapshot row capturing the approved contract at that revision. Every Subtask `AgentRun` of `kind=execute` gains a snapshot FK pointing at the approved current Plan it was authorised against.**

Concrete shape:

- New table `story_snapshots`:
  - `id`, `story_id`, `revision` (integer; unique with `story_id`), `created_at`.
  - `body` (JSON; serialised Story kind, actor, intent, outcome, description, and notes).
  - `acceptance_criteria` (JSON; ordered list of AC text + position).
  - `scenarios` (JSON; ordered list of Given/When/Then paths).
  - Snapshots are **append-only**; rows are never updated.
- New table `plan_snapshots`:
  - `id`, `plan_id`, `story_snapshot_id`, `revision` (integer; unique with `plan_id`), `created_at`.
  - `plan` (JSON; ordered list of Tasks, criterion/scenario links, and ordered Subtasks).
  - Snapshots are **append-only**; rows are never updated.
- `agent_runs.story_snapshot_id` and `agent_runs.plan_snapshot_id` (nullable FKs). Backfilled to NULL for existing rows; execution runs are populated from the current approving `PlanApproval` path via `ExecutionService::dispatchSubtaskExecution`.
- Snapshots are taken **at approval time**, not at dispatch time. `ApprovalService::recompute()` writes the Story snapshot when Story approval reaches threshold. `ApprovalService::recomputePlan()` writes the Plan snapshot when Plan approval reaches threshold. This guarantees snapshots reflect the human-approved state rather than a state the executor's append-only growth (ADR-0005) might have already drifted past.
- Append-only growth (ADR-0005) does **not** create a new snapshot. The growth is part of the run's output, not part of the approved Plan; the snapshot is the plan-as-approved.
- Edits that reopen approval (ADR-0001) bump the relevant revision and clear no snapshots — old snapshots stay forever as audit records. The next approval writes the next snapshot at the new revision.
- `respond_to_review` runs (ADR-0008) do **not** carry a `story_snapshot_id` — they don't change spec; their authorising context is the parent `execute` run.

UI consequences (not part of this ADR's schema, but the reason it's being proposed):

- Run console header gains a `View story at v7` link → read-only render of the snapshot JSON.
- "Story has changed since this run started" drift indicator appears when `Story.revision > AgentRun.story_snapshot.revision`.
- Story document right rail can list "approved revisions" (snapshot count + most recent approval timestamps).

## Consequences

### Positive

- The "what was approved" question becomes queryable: `agent_runs.story_snapshot_id → story_snapshots.*` reads exactly the spec the human authorised, regardless of what the live Story looks like now.
- ADR-0001's approval gates gain concrete artefacts in the data model — snapshots are the proof.
- The UI can honestly render drift between "what was approved" and "what is current" without lying about capabilities (UI brief Principle 4).

### Negative

- **Storage growth.** Every approval writes a snapshot; large Stories (50+ Subtasks) will produce non-trivial JSON blobs. Mitigation: at scale, prune snapshots older than N revisions per Story (with a config knob); not in V1.
- **Backfill ambiguity.** Existing `execute` runs have `story_snapshot_id = NULL`. Either accept the gap (old runs simply have no snapshot, UI shows "snapshot unavailable") or run a one-shot best-effort backfill from current Story state for runs where revisions still match. Lean: accept the gap.
- **Snapshot drift from running runs.** If a snapshot is written at approval time but the executor's append-only growth (ADR-0005) extends the plan during the run, the snapshot will not reflect the appended Subtasks. This is intentional — the snapshot is "what was approved," not "what was executed" — but it must be documented so reviewers don't read it as a contradiction.

### Neutral

- ADR-0001 is preserved: Story and current Plan remain the approval gates; snapshots are audit artefacts, not new gates.
- ADR-0005 is preserved: append-only growth still does not reset approval and still does not produce a new snapshot.
- ADR-0008 is preserved: `respond_to_review` runs don't snapshot because they don't change spec.

## Open questions

- Snapshot pruning policy at scale — flag knob, retention horizon, or both?
- Should the snapshot include a hash of its own JSON for tamper detection? Probably yes; small column, big audit value.
- Should snapshots be written **before** the cascade dispatches the first Subtask, or **after**? Lean: before — the cascade should never run against an unwritten snapshot.
