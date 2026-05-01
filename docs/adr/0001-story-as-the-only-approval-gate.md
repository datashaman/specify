# 0001. Story is the only approval gate

Date: 2026-05-01
Status: Accepted

## Context

Specify's premise is that humans gate AI work on real codebases. The earliest design proposed gating at three levels — Story, Plan, and Task — so reviewers could veto at any stage. In practice this produced approval fatigue: each story dragged three review cycles before an executor ever touched a repo, and Plan/Task approvals were almost always rubber-stamped because reviewers had already said yes at the Story level.

We needed a single, meaningful approval surface that binds intent to work without putting humans in the loop on every engineering step.

## Decision

**Story is the only approval gate.** Tasks and Subtasks are not gated.

- `StoryApproval` is the only approval table; `PlanApproval` was removed.
- `ApprovalPolicy` (workspace/project/story scope cascade, with `required_approvals` threshold) governs how many Approve decisions a Story needs.
- `ApprovalService::recordDecision/recompute` runs the state machine: Approve counts unique approvers toward the threshold, ChangesRequested resets, Reject is terminal, Revoke cancels prior decisions.
- Any subsequent edit to a Story's tasks or subtasks resets the Story to `PendingApproval`. Re-approving the Story re-authorises the (now-edited) plan beneath it.
- The diff-review surface for engineering output is the **pull request**, not an in-app approval table.

## Consequences

Easier:
- One approval per story to ship work; reviewers see one decision, not three.
- The PR is where engineering review happens — familiar tooling, line-level comments, CI signal.
- `AgentRun.authorizing_approval` is always a `StoryApproval`, simplifying the polymorphic relation.

Harder / accepted trade-offs:
- A reviewer who approves a story is implicitly approving its current task plan. Edits to the plan revoke that approval; the bookkeeping for "what changed since last approval" lives in `Story::revision` and the task/subtask diff.
- Fine-grained "approve task 3 but not task 4" is no longer supported. If a reviewer wants partial work, they request changes on the Story.

Follow-ups:
- Per-task AI gates were ruled out (see user-memory note `feedback_no_per_task_ai_gates`); honour this when proposing new automation.
