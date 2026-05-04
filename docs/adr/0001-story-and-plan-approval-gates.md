# 0001. Story and current Plan are the approval gates

Date: 2026-05-01
Status: Accepted

## Context

Specify's premise is that humans gate AI work on real codebases. The earliest design proposed gating at three levels — Story, Plan, and Task — so reviewers could veto at any stage. A later simplification collapsed that to Story-only approval and removed the Plan noun.

That simplification went too far. A Story is the product contract: who benefits, what outcome is expected, and what acceptance criteria and scenarios define success. A Plan is the implementation interpretation of that contract: Tasks and Subtasks the executor will run. Approving the product contract should not silently approve every future implementation plan, and replacing the implementation plan should not require pretending the product contract changed.

We still do not want approval fatigue. Task and Subtask approval gates remain too granular for this system.

## Decision

**StoryApproval gates the product contract. PlanApproval gates the current implementation plan. Tasks and Subtasks are not approval gates.**

- `StoryApproval` is an immutable audit log for Story-level decisions.
- `PlanApproval` is an immutable audit log for Plan-level decisions.
- `ApprovalPolicy` (workspace/project/story scope cascade, with `required_approvals` threshold) governs how many Approve decisions each gate needs. Plans use the effective policy of their Story.
- `ApprovalService::recordDecision/recompute` runs the Story state machine. `ApprovalService::recordPlanDecision/recomputePlan` runs the Plan state machine.
- Execution requires both an approved Story and an approved current Plan. Subtask AgentRuns authorise against the current approving `PlanApproval`.
- Task-generation runs may authorise against a `StoryApproval`, because generation interprets the approved product contract into a Plan.
- Product contract edits — Story body, acceptance criteria, or scenarios — bump the Story revision, reopen Story approval, and reopen the current Plan approval because the implementation interpretation may now be stale.
- Plan edits — replacing the Plan, changing Tasks, or changing Subtasks — reopen Plan approval. They do not reopen Story approval unless the product contract also changed.
- Executor-driven append-only growth during an approved run is the ADR-0005 carve-out: it may append Subtasks under the running Plan without introducing a per-Subtask approval gate.
- The diff-review surface for engineering output is the pull request, not an in-app Task or Subtask approval table.

## Consequences

Easier:

- Reviewers make two meaningful decisions: "is this the right product contract?" and "is this the right implementation plan?"
- Replacing an implementation plan does not distort Story history.
- Execution authorisation is explicit: `AgentRun.authorizing_approval` points to the approval row that sanctioned that kind of run.

Harder / accepted trade-offs:

- There are two approval logs and two recompute paths to keep semantically aligned.
- UI copy must be precise about which gate is pending: Story approval for product contract changes, Plan approval for delivery-plan changes.
- Fine-grained "approve Task 3 but not Task 4" is still unsupported. If a reviewer wants partial work, they request Plan changes.

Follow-ups:

- Per-task AI gates were ruled out (see user-memory note `feedback_no_per_task_ai_gates`); honour this when proposing new automation.
