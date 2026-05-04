# Story planning model

Specify separates product intent from delivery execution.

```text
Workspace
  -> Project
    -> Feature
      -> Story
        -> AcceptanceCriterion / Scenario
        -> Plan
          -> Task
            -> Subtask
```

This page describes the current architecture. The load-bearing decisions are [ADR-0001](../adr/0001-story-and-plan-approval-gates.md) and [ADR-0002](../adr/0002-story-scenario-plan-task-subtask-hierarchy.md).

## Product contract

A Story is the product contract. It answers what should exist and why it matters.

Story-owned product fields:

- `kind`
- `actor`
- `intent`
- `outcome`
- `description`
- `notes`
- `revision`

Story-owned product children:

- `AcceptanceCriterion` — one atomic observable rule, stored as `statement`.
- `Scenario` — one Given / When / Then behaviour example. A Scenario may reference an AcceptanceCriterion.

Product edits include Story body edits, acceptance-criterion edits, and scenario edits. Product edits bump the Story revision, reopen Story approval, and reopen current Plan approval because the implementation interpretation may now be stale.

The main product-contract write modules are:

| Module | Responsibility |
|---|---|
| `App\Services\Stories\StoryWriter` | Creates and updates Stories from MCP/tool flows. |
| `App\Services\Stories\AcceptanceCriteriaWriter` | Adds or replaces acceptance criteria as one Story content change. |
| `App\Services\Stories\ScenarioWriter` | Adds or updates scenarios as one Story content change. |
| `App\Services\Stories\StoryContractEditor` | Edits the Story show page contract form while preserving existing AcceptanceCriterion IDs. |
| `App\Services\Stories\StoryRevisionLifecycle` | Owns revision bumps and approval reopening for product-contract changes. |

`StoryContractEditor` preserves AcceptanceCriterion IDs when editing existing criteria. That matters because current Plan Tasks may point at those criteria. A submitted criterion ID that does not belong to the Story is rejected instead of treated as a new row.

## Delivery plan

A Plan is the implementation interpretation of a Story. A Story may have many Plans, but only one current Plan.

Current Plan selection:

- `plans.story_id` owns Plan history under the Story.
- `stories.current_plan_id` points to the active Plan.
- Previous Plans remain queryable as history.
- Replacing the Plan supersedes the prior current Plan and moves `stories.current_plan_id`.

Plan-owned delivery children:

- `Task` — actionable delivery work item under a Plan.
- `Subtask` — executor-sized engineering step under a Task.

Task ownership is always `Task -> Plan -> Story`. There is no `tasks.story_id`. Code starting from a Task or Subtask resolves the Story through the Plan.

A Task may reference:

- `acceptance_criterion_id`
- `scenario_id`

Those references are traceability links. They do not make a Task equal to one acceptance criterion or one scenario.

The main delivery-plan write modules are:

| Module | Responsibility |
|---|---|
| `App\Services\PlanWriter` | Replaces the current Plan and writes Tasks/Subtasks in one place. |
| `App\Services\Plans\CurrentPlanSelector` | Moves `stories.current_plan_id` safely. |
| `App\Services\Plans\PlanVersionAllocator` | Allocates next Plan versions within a Story. |
| `App\Services\Plans\PlanApprovalLifecycle` | Submits and reopens current Plan approval. |
| `App\Services\Plans\PlanInputNormalizer` | Normalizes generated Plan input before writing. |

## Approval gates

There are two approval gates:

- Story approval gates the product contract.
- Current Plan approval gates execution.

There are no Task or Subtask approval gates.

Approval records are immutable audit rows:

- `StoryApproval`
- `PlanApproval`

`ApprovalService` records decisions and recomputes approval state. Story and Plan approvals both replay decisions for the current revision. Approve counts toward the effective threshold, Revoke removes that approver from the effective set, ChangesRequested resets the effective set, and Reject is terminal.

Execution requires both:

- an approved Story
- an approved current Plan

Subtask execution AgentRuns authorize against the current approving `PlanApproval`. Plan-generation AgentRuns may authorize against a `StoryApproval` because plan generation interprets the approved product contract.

## Execution flow

The normal run path is:

```text
Story submitted
  -> Story approved
  -> Plan generated or replaced
  -> current Plan submitted
  -> current Plan approved
  -> next actionable Subtasks dispatched
  -> executor commits and pushes
  -> pull request opened
  -> Subtask / Task / Plan / Story completion cascades
```

The main execution modules are:

| Module | Responsibility |
|---|---|
| `App\Services\ExecutionService` | Public orchestration surface for generation, execution, retry, cancel, PR retry, and conflict resolution. |
| `App\Services\SubtaskExecutionScheduler` | Dispatches executable Subtasks and advances Subtask -> Task -> Plan -> Story completion. |
| `App\Services\SubtaskRunPipeline` | Runs the executor against a workdir and records the result. |
| `App\Jobs\GenerateTasksJob` | Invokes plan generation and writes the new current Plan. |
| `App\Jobs\ExecuteSubtaskJob` | Executes a queued Subtask AgentRun. |
| `App\Jobs\OpenPullRequestJob` | Opens a PR after push; PR-open failures are non-fatal. |

The completion cascade belongs to `SubtaskExecutionScheduler::finalizeSubtaskFromRun()`. Callers should not bulk-update active Subtask AgentRuns to terminal states without going through `ExecutionService::markAborted()`, `markFailed()`, or `markCancelled()`, because those methods trigger finalization.

## Story page modules

The Story show page is a Livewire Volt page that now delegates most behaviour to focused modules.

| Module | Responsibility |
|---|---|
| `resources/views/pages/stories/⚡show.blade.php` | UI state, Livewire actions, render orchestration, and computed view data. |
| `resources/views/partials/story-show/*` | Presentational sections for the Story show page. |
| `App\Services\Stories\StoryPageWorkflow` | Story show workflow actions: submit, decisions, plan submission/decisions, generation, conflict resolution, resume/start execution. |
| `App\Services\Stories\StoryContractEditor` | Story show edit form persistence and product-contract lifecycle. |
| `App\Services\Stories\StoryRunProjection` | Story current-Plan activity projection for run, branch, repo, and plan-generation history. |
| `App\Services\Stories\StoryPullRequestProjection` | Story PR projection across Execute-kind Subtask runs. |

The page still owns immediate UI concerns: edit mode, form fields, decision notes, flash messages, redirects, and choosing which partials to render.

## Read-side projections

Story read-side projections keep expensive or semantic lookups out of Blade.

`StoryRunProjection` owns:

- active Subtask run detection under the current Plan
- active conflict-resolution run lookup
- latest current-Plan run lookup
- latest branch/repo surfaced on the Story page
- bounded plan-generation run history for the current Story
- current-Plan view data for the Story page Plan section

`StoryPullRequestProjection` owns:

- PR entries produced by Execute-kind Subtask runs
- race-mode PR candidate ordering
- primary PR selection
- best-effort GitHub mergeability probing

## MCP surface

The MCP tool surface uses the same hierarchy:

- create or update product contract data through Story, AcceptanceCriterion, and Scenario tools
- create, list, set, submit, approve, reject, or request changes on Plans
- create or update Tasks and Subtasks under Plans
- start execution only when the Story and current Plan are approved

Important MCP terms:

- "Story product contract" means Story body, criteria, and scenarios.
- "Current plan" means the Plan selected by `stories.current_plan_id`.
- "Tasks" means Plan-owned Tasks.
- "Subtasks" means Task-owned executor steps.

## Invariants to preserve

- Do not add `tasks.story_id`.
- Do not add Task or Subtask approval gates.
- Do not mutate old `StoryApproval`, `PlanApproval`, or `AgentRun` audit rows.
- Do not treat a Task as a single acceptance criterion.
- Do not bypass `PlanWriter::replacePlan()` for Plan replacement.
- Do not bypass `StoryRevisionLifecycle` for product-contract edits.
- Do not start execution without both an approved Story and an approved current Plan.
- Do not fail an AgentRun because PR creation failed; record `pull_request_error` and let the run succeed.
