# 0002. Story, Scenario, Plan, Task, Subtask hierarchy

Date: 2026-05-01
Status: Accepted

## Context

The domain has two different questions that should not be collapsed:

- **Product contract:** what the human wants and how success is recognised.
- **Delivery plan:** how the AI proposes to change a repo to satisfy that contract.

The temporary `Story -> Task -> Subtask` model made Tasks carry both meanings. It also forced every Task to look like exactly one acceptance criterion, which broke down as soon as a useful implementation step cut across criteria or belonged to a scenario path rather than a single statement.

Greenfield means we should model the language we actually need, not preserve a shortcut that was only useful during transition.

## Decision

Specify uses a product layer and a delivery layer:

```text
Workspace -> Project -> Feature -> Story
  -> AcceptanceCriterion / Scenario
  -> Plan -> Task -> Subtask
```

- `Story` is the product-owner unit of value. It carries kind, actor, intent, outcome, description, notes, revision, acceptance criteria, and scenarios.
- `AcceptanceCriterion` belongs to Story and stores the canonical `statement`.
- `Scenario` belongs to Story and stores Given/When/Then-style example paths.
- `Plan` belongs to Story and is the implementation interpretation of the product contract. `stories.current_plan_id` points at the active Plan.
- `Task` belongs to Plan, never directly to Story. Code that needs the Story resolves it through `Task -> Plan -> Story`.
- `Task` may reference an acceptance criterion and/or a scenario, but `Task` is not defined as "one acceptance criterion." It is a delivery work item under a Plan.
- `Subtask` belongs to Task and is the executor's unit of work. One `ExecuteSubtaskJob` runs one Subtask.
- Plan replacement creates a new Plan, supersedes the previous Plan, and moves `stories.current_plan_id`. Previous Plans remain queryable as history.
- The MCP and agent language uses Plan/Task/Subtask consistently: plan generation writes a Plan, Tasks, and Subtasks; execution runs Subtasks.

## Consequences

Easier:

- Product and delivery concerns have separate ownership and approval gates (ADR-0001).
- Alternate or superseded Plans can exist without mutating the Story's product contract.
- The data model has one ownership path for delivery work: `Task -> Plan -> Story`. There is no duplicate `tasks.story_id` to drift.
- Tasks can be shaped around real implementation slices instead of being forced into one-criterion rows.

Harder / accepted trade-offs:

- Callers that start from Task or Subtask must traverse through Plan to reach Story.
- Plan replacement has to keep history coherent: supersede old Plan, create new Plan, move `current_plan_id`, and reopen Plan approval in one place.

Follow-ups:

- Keep `PlanWriter::replacePlan()` as the single write seam for replacing delivery plans so revision and approval semantics stay local.
