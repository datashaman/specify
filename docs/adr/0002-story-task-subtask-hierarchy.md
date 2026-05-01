# 0002. Story → Task → Subtask hierarchy (Plan retired)

Date: 2026-05-01
Status: Accepted

## Context

The original model placed a `Plan` between Story and Task as a separately-gated artifact: a Story's plan could be regenerated, re-reviewed, and replaced without touching the Story itself. This duplicated state (a Plan had its own status enum, its own approval flow, its own version) and added a hop that nothing in the executor or MCP surface needed.

Once we accepted that Story is the only approval gate (ADR-0001), the Plan layer became a renaming of "the story's tasks" — a noun with no behaviour.

## Decision

Drop the `Plan` model. Tasks attach directly to Stories. Each Task carries one or more `Subtask` rows that are the executor's actual step list.

- Migration `2026_04_30_092722_drop_plan_attach_tasks_to_stories_add_subtasks` removed the `plans` table, repointed `tasks.story_id`, and added the `subtasks` table.
- `Task` is the engineering contract for **one acceptance criterion**.
- `Subtask` is the unit the executor runs (one Subtask per `ExecuteSubtaskJob` invocation).
- Plan-generation language survives in the agent name (`PlanGenerator`) and the MCP tool slug (`generate-plan`) for caller continuity, but the artifact produced is a list of Tasks + Subtasks under the Story, not a `Plan` row.

## Consequences

Easier:
- One ownership chain: `Story → Task → Subtask` — no detached Plan that can drift from its Story.
- `AgentRun.runnable` is polymorphic over Subtask (and Story for task-generation runs); no `Plan` variant.
- Editing tasks resets Story approval cleanly (ADR-0001) — no second cascade through Plan.

Harder / accepted trade-offs:
- A Story can no longer hold multiple alternative plans for comparison; if reviewers want to see alternatives they request changes and the next regeneration replaces the task list.
- Naming continuity costs: external callers still see "generate-plan" as the MCP tool slug while the internal model talks about Tasks. The MCP layer is the only place this leaks.

Follow-ups:
- Consider renaming `PlanGenerator` and the `generate-plan` MCP tool to align with the internal model, with a deprecation period.
