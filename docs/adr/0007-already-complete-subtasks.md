# 0007. Already-complete subtasks

Date: 2026-05-01
Status: Accepted

## Context

The Subtask pipeline ran an agent against a working copy and treated *no
diff* as a hard failure: run marked Failed, Subtask flipped to Blocked,
cascade stalled. That rule was the safety net for an agent that crashed,
hallucinated, or simply did nothing — without it, an empty diff would
look like success.

In practice it has another, recurring failure mode: a Subtask whose work
is *legitimately* already on the branch, bundled into an earlier Subtask's
commit. The auth-on-mutation-endpoints case (subtask 37 of story 11) was
the prompting example — the create/update/delete subtasks introduced
FormRequests with `authorize()` baked in, and by the time subtask 37 ran
"Enforce manage permission on mutation endpoints" the spec was already
satisfied. The agent correctly inspected the branch, said "Confirmed:
already enforces server-side manage authorization," produced no changes,
and got marked failed. Subtask 28 hit the same pattern earlier on the
same story.

Treating no-diff as "Done, the spec is met" silently bypasses the only
human gate (Story approval — ADR-0001) for any Subtask the agent decides
not to act on. Treating no-diff as "Failed" stalls the cascade on every
spec-satisfied Subtask. Neither extreme is right.

## Decision

**A no-diff outcome is treated as "already complete" — and so as a
successful no-op the cascade can advance past — only when the agent
explicitly declares it AND points at the commit SHAs that cover the
work. Otherwise, no-diff stays a hard failure.**

Concrete shape:

- `ExecutionResult` gains two fields: `alreadyComplete: bool` (default
  false) and `alreadyCompleteEvidence: list<string>` (commit SHAs the
  agent claims cover the spec, default empty).
- `SubtaskRunOutcome` gains a new state `STATE_ALREADY_COMPLETE` and a
  factory `alreadyComplete($output)`. It is Succeeded-class —
  `isSucceeded()` returns true, the queue job routes it to
  `markSucceeded`, and the Subtask cascade advances.
- The pipeline accepts the outcome only when **all** of the following
  hold after the agent runs and `git commit` produces no SHA:
  - `result->alreadyComplete === true`
  - `result->alreadyCompleteEvidence !== []`
  - Every SHA in the evidence list is reachable from `HEAD` on the
    working branch (`git merge-base --is-ancestor <sha> HEAD`).
- If any check fails (the agent didn't set the flag, gave no commits,
  or named a commit that isn't actually on the branch), the run falls
  through to the existing `noDiff` Failure path. That preserves the
  safety net for hallucinated or empty agent runs.
- The agent's evidence list and summary are persisted on
  `agent_runs.output.already_complete` / `.already_complete_reason`
  so post-hoc audit can ask: was the agent right?
- Both executors are wired:
  - `LaravelAiExecutor` reads the fields directly from the structured
    output schema (`SubtaskExecutor` adds them).
  - `CliExecutor` parses a sentinel block in stdout —
    `<<<SPECIFY:already_complete>>>sha1,sha2<<<END>>>` — emitted by
    the agent's transcript. Free-text CLI agents that don't emit the
    sentinel keep the old behaviour (no-diff fails).

## Consequences

### Positive

- The "spec already satisfied" pattern stops blocking the cascade
  without hand-flipping subtasks in the database.
- The evidence requirement makes the assertion auditable. A reviewer
  asking "did this Subtask actually get done?" can `git show <sha>`
  on the named commits — the agent has to point at *something real*,
  not just say so.
- Hallucinated "already done" claims (no commits, or named commits
  that don't exist on the branch) still fail. The safety net stays.

### Negative

- The agent can still point at commits that don't *actually* cover
  the spec — only at commits that exist. Evidence proves
  reachability, not correctness. Mitigation: the PR diff review
  surface (ADR-0001) is the same place a human reviews any Subtask's
  changes; the open PR for the parent branch will include the cited
  commits, and a reviewer reading that diff sees what the agent
  asserted was already done.
- CLI executors that don't emit the sentinel keep the old loop. That
  is intentional — opt-in, not magic detection of free-text claims.
  Agents that want this affordance must be told to emit the sentinel.
- `ExecutionResult` grows. Existing executors and tests that
  construct it without these fields keep working (defaults), so the
  change preserves the existing run schema.

### Neutral

- ADR-0001 is preserved: Story and current Plan remain the approval
  gates. The reviewer's diff-review surface (the PR) shows the cited
  commits alongside everything else on the branch.
- ADR-0004 is preserved: the new state is Succeeded-class, like
  `pullRequestFailed`. Both route to `markSucceeded`.

## Open questions (future work, not blocking)

- Should the evidence requirement be tightened — e.g. the agent must
  cite at least one commit whose touched files overlap the Subtask's
  scope? Today reachability-from-HEAD is the only check.
- Should losing-race siblings inherit each other's "already complete"
  evidence? (No — they ran on different branches; the evidence list
  is branch-local.)
