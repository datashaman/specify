# Approval architecture

Specify has two human gates:

- Story approval gates the product contract.
- Current Plan approval gates the implementation plan.

Tasks and Subtasks are not approval gates. PR review is not an approval gate.

This page describes the current implementation shape. The load-bearing decision
is [ADR-0001](../adr/0001-story-and-plan-approval-gates.md). The hierarchy
being approved is covered by [Story planning model](story-planning-model.md).

## Gate targets

Story approval answers: "Is this product contract worth implementing?"

Story-owned contract data includes:

- Story body fields such as name, kind, actor, intent, outcome, description,
  and notes
- acceptance criteria
- scenarios

Plan approval answers: "Is this implementation interpretation acceptable?"

Plan-owned delivery data includes:

- current Plan fields
- Tasks
- Subtasks
- Task links to acceptance criteria, scenarios, and dependencies

Execution requires both the Story and the current Plan to be approved. A Story
can be approved while its current Plan is still draft, pending, or changes
requested.

## Core records

| Record | Purpose |
|---|---|
| `ApprovalPolicy` | Threshold and self-approval rules for a scope. |
| `StoryApproval` | Immutable decision row for one Story revision. |
| `PlanApproval` | Immutable decision row for one Plan revision. |

`StoryApproval` and `PlanApproval` are append-only audit rows. Updates and
deletes are blocked by model hooks. Corrections happen by recording another
decision, such as `revoke` or `changes_requested`.

Approval rows are filtered by the target's current revision during recompute.
Older revision rows stay in the database but do not count toward the current
gate.

The approval-row tables allow multiple decisions from the same approver on the
same revision. `ApprovalGate` replays all rows in creation order and maintains
the effective approver set in memory.

## Policy resolution

`ApprovalPolicy` stores:

- `scope_type`
- `scope_id`
- `required_approvals`
- `allow_self_approval`
- `auto_approve`
- `notes`

Story policy resolution is:

```text
Story policy
  -> Project policy
    -> Workspace policy
      -> default policy
```

Plan policy resolution delegates to the Story's effective policy. Plan-scoped
policy constants exist on the model, but the current resolver does not read
Plan-scoped policies.

Default policy:

```text
required_approvals = 0
allow_self_approval = false
auto_approve = false
```

With `required_approvals = 0`, submitting a non-draft gate moves it to
approved during recompute without creating an approval row. This is why
AgentRun `authorizing_approval` can legitimately be null even though the gate
was approval-satisfied.

`auto_approve = true` also makes the gate approval-satisfied regardless of the
effective approval count. Draft targets are not auto-promoted until they are
submitted.

## Decisions

Approval decisions are:

| Decision | Replay effect |
|---|---|
| `approve` | Adds the approver to the effective approval set. |
| `revoke` | Removes the approver from the effective approval set. |
| `changes_requested` | Clears the effective approval set and moves the gate back to review state. |
| `reject` | Terminally rejects the gate. |

`reject` short-circuits replay. Once a Story or Plan is rejected,
`ApprovalService` rejects further decisions for that target.

`changes_requested` behaves differently by target:

- Story moves to `changes_requested`.
- Plan moves to `pending_approval`.

That is intentional: Plan has no separate `changes_requested` enum value.

## State machine

`ApprovalService` records decisions and asks `ApprovalGate` to recompute the
target state.

Story path:

```text
Story::submitForApproval()
  -> StoryApprovalSubmission::submit()
  -> status = pending_approval
  -> ApprovalService::recompute()
  -> ApprovalGate::nextStatus()
```

Plan path:

```text
Plan::submitForApproval()
  -> PlanApprovalLifecycle::submit()
  -> status = pending_approval
  -> ApprovalService::recomputePlan()
  -> ApprovalGate::nextStatus()
```

Decision recording:

```text
ApprovalService::recordDecision()
  -> create StoryApproval
  -> recompute Story

ApprovalService::recordPlanDecision()
  -> create PlanApproval
  -> recompute Plan
```

Recompute rules:

- Any `reject` decision returns rejected.
- Latest effective `changes_requested` state returns the target's review-state
  status.
- `approve` decisions count once per approver.
- `revoke` removes that approver from the count.
- The gate is approved when `auto_approve` is true or the effective approver
  count meets `required_approvals`.
- Draft targets stay draft until submitted.

## Submission guards

Story submission guards:

- rejected Stories cannot be submitted
- Story must have at least one acceptance criterion

Plan submission guards:

- only the Story's current Plan can be submitted
- rejected Plans cannot be submitted
- Plan must have at least one Task

Plan decisions have extra guards:

- only the current Plan can receive decisions
- draft Plans cannot receive decisions before submission
- rejected and superseded Plans cannot receive decisions

Self-approval is rejected for `approve` decisions when the effective policy has
`allow_self_approval = false` and the approver is the Story creator. This guard
also applies to Plan approval because a Plan inherits the Story policy and
creator.

## Reopening

Product-contract edits reopen Story approval and current Plan approval.

Product-contract edits include:

- watched Story body fields
- acceptance criteria changes
- scenario changes

Story body edits bump `stories.revision` through `StoryRevisionLifecycle`.
Acceptance-criterion and scenario changes call
`StoryRevisionLifecycle::recordContentArtifactChanged()`.

When Story revision changes:

- non-draft, non-terminal Stories move back to the policy-derived review state
- current Plan approval is reopened because the implementation interpretation
  may be stale
- prior Story approval rows remain stored but no longer count because their
  `story_revision` is old

Plan edits reopen only Plan approval. They do not reopen Story approval unless
the product contract also changed.

Plan approval reopening increments `plans.revision` and sets status according
to the current Plan state:

| Current Plan status | Reopen result |
|---|---|
| `draft` | stays `draft` |
| `superseded` | stays `superseded` |
| `done` | stays `done` |
| any other status, including `rejected` | moves to `pending_approval`, then recomputes |

Executor-proposed Subtasks appended mid-run are the ADR-0005 exception: they
grow the Plan append-only and do not reopen approval.

## Authorisation and AgentRuns

Plan generation interprets an approved Story into a Plan. When a concrete
`StoryApproval` row is available, the generated `AgentRun` may point to it
through `authorizing_approval`.

Subtask execution requires an approved Story and approved current Plan. When a
concrete `PlanApproval` row is available, Execute AgentRuns may point to it
through `authorizing_approval`.

Auto-approval paths can approve without creating a `StoryApproval` or
`PlanApproval` row, so `authorizing_approval` is nullable by design.

Retries re-check the current Story and Plan approval state. A retry is rejected
if the Story or current Plan is no longer approved.

## Web and MCP surfaces

Primary web surfaces:

- Story page product-contract approval actions
- Story page current-Plan approval actions
- Project approval views

Primary MCP surfaces:

- `submit-story`
- `approve-story`
- `request-story-changes`
- `reject-story`
- `submit-plan`
- `approve-plan`
- `request-plan-changes`
- `reject-plan`

MCP approval tools use `RecordsApprovalDecisions` and
`ResolvesProjectAccess`. The user must be able to access the owning Project and
must have approver rights in that Project.

## Invariants to preserve

- Do not add Task or Subtask approval gates.
- Do not mutate or delete StoryApproval or PlanApproval rows.
- Do not count approval rows from older revisions.
- Do not treat `authorizing_approval = null` as unauthorised by itself;
  auto-approval can satisfy the gate without a concrete row.
- Do not approve a draft Story or Plan without submission.
- Do not allow decisions on non-current Plans.
- Do not reopen Story approval for delivery-plan-only edits.
- Do not reopen Plan approval for ADR-0005 append-only executor growth.
- Do not bypass `ApprovalService` for approval decisions.
- Do not bypass `StoryRevisionLifecycle` or `PlanApprovalLifecycle` when edits
  should reopen approval.
