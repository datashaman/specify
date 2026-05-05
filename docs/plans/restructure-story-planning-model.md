# Restructure story and planning model

Related:
- GitHub issue: https://github.com/datashaman/specify/issues/47
- Specify feature: `Structured story and planning model`
- Specify story: `Add structured story framing, scenarios, and plans`

## Status

Historical. Implemented by PR #49 and subsequent cleanup through PR #91.
This document is retained to explain the implementation campaign that moved
Specify to the current Story/Plan/Task/Subtask structure.

Do not use this file as a backlog. The canonical decisions now live in
[ADR-0001](../adr/0001-story-and-plan-approval-gates.md) and
[ADR-0002](../adr/0002-story-scenario-plan-task-subtask-hierarchy.md), and the
current implementation shape is documented in
[`docs/architecture/story-planning-model.md`](../architecture/story-planning-model.md).

The historical implementation targets below preserve the original planning
shape. They are not open tasks.

Important constraint:
- Existing Specify data is **not sacred**.
- Prefer the **cleanest target model**.

---

## Goal

Refactor Specify's product and planning model so that these concepts each have a clear first-class home:

- `As a ...`
- `I want ...`
- `So that / in order to ...`
- `The system shall ...`
- `Given / When / Then ...`
- proposal / design / implementation planning
- executable tasks and subtasks

Greenfield cleanup constraint:
- Keep only the target model names, ownership columns, and traversal helpers.
- The product layer is `Feature -> Story -> AcceptanceCriterion/Scenario`.
- The delivery layer is `Story -> Plan -> Task -> Subtask`.
- Cross-links are explicit: `Story.current_plan_id`, optional `Scenario.acceptance_criterion_id`, optional `Task.acceptance_criterion_id`, and optional `Task.scenario_id`.
- `Task` must not store `story_id`; resolve the Story through `Task -> Plan -> Story`.
- Acceptance criteria must use `statement` only; remove `criterion` columns, attributes, request aliases, and tests.

The current structure stores features, stories, acceptance criteria, tasks, and subtasks, but it mixes product structure with implementation structure and flattens behavior examples into text blobs.

---

## Target information architecture

### Product layer
- **Feature** — enduring capability
- **Story** — unit of user or requirement value
- **AcceptanceCriterion** — atomic observable rule
- **Scenario** — behavior example with Given / When / Then structure

### Delivery layer
- **Plan** — one implementation interpretation of a story
- **Task** — actionable work item under a plan
- **Subtask** — executor-sized step under a task

### Execution / governance layer
- **StoryApproval** — approval of the product contract
- **PlanApproval** — approval of the implementation plan
- **AgentRun** — execution and review history

### Core rule

> Story owns the product contract; Scenario owns behavior examples; Plan owns implementation intent; Task owns executable work.

---

## Mapping of common requirement forms

### Story framing
These belong on `Story`:

- `As a ...` → `actor`
- `I want ...` → `intent`
- `So that / in order to ...` → `outcome`

### Product rules
These belong on `AcceptanceCriterion`:

- `The system shall ...`
- short, atomic, observable rules

### Behavior examples
These belong on `Scenario`:

- `Given ...`
- `When ...`
- `Then ...`

### Delivery detail
These belong on `Plan`, `Task`, and `Subtask`:

- proposal summary
- design notes
- risks
- assumptions
- engineering steps

---

## Target schema

## `features`

Keep mostly as-is.

Fields:
- `id`
- `project_id`
- `name`
- `slug`
- `description`
- `notes`
- `status`
- timestamps

---

## `stories`

Stories become structured product slices.

Fields:
- `id`
- `feature_id`
- `created_by_id`
- `name`
- `slug`
- `kind`
- `actor` nullable
- `intent` nullable
- `outcome` nullable
- `description` nullable
- `notes` nullable
- `status`
- `revision`
- `position`
- `current_plan_id` nullable
- timestamps

### `kind`
Suggested enum values:
- `user_story`
- `requirement`
- `enabler`

Reason:
Not every story is naturally written as a user story. Imported requirements often fit better as `requirement`.

---

## `acceptance_criteria`

Acceptance criteria should store short rules, not whole scenarios.

Fields:
- `id`
- `story_id`
- `position`
- `statement`
- timestamps

Examples:
- Only valid status transitions are shown.
- The resend button disables for 5 minutes after success.
- Tenant admins cannot be edited from the event level.

---

## `scenarios`

New first-class table.

Fields:
- `id`
- `story_id`
- `acceptance_criterion_id` nullable
- `position`
- `name`
- `given_text` nullable
- `when_text` nullable
- `then_text` nullable
- `notes` nullable
- timestamps

Indexes:
- `(story_id, position)`
- `acceptance_criterion_id`

Reason:
This gives Given / When / Then a proper home instead of flattening them into criterion text.

---

## `plans`

Plans are first-class again and represent implementation interpretation, not product contract.

Fields:
- `id`
- `story_id`
- `version`
- `name`
- `summary` nullable
- `design_notes` nullable
- `implementation_notes` nullable
- `risks` nullable
- `assumptions` nullable
- `source`
- `source_label` nullable
- `status`
- timestamps

### `source`
Suggested enum values:
- `human`
- `ai`
- `imported`

### `status`
Suggested enum values:
- `draft`
- `pending_approval`
- `approved`
- `superseded`
- `rejected`
- `done`

`stories.current_plan_id` should point at the active plan.

---

## `tasks`

Tasks belong to plans, not directly to stories.

Fields:
- `id`
- `plan_id`
- `acceptance_criterion_id` nullable
- `scenario_id` nullable
- `position`
- `name`
- `description` nullable
- `status`
- timestamps

Notes:
- tasks may optionally point to a criterion
- tasks may optionally point to a scenario
- do **not** require 1 task = 1 criterion

---

## `subtasks`

Keep mostly as-is.

Fields:
- `id`
- `task_id`
- `position`
- `name`
- `description`
- `status`
- `proposed_by_run_id` nullable
- timestamps

---

## `story_approvals` and `plan_approvals`

Keep both, but clarify semantics.

- `story_approvals` approve the product contract
- `plan_approvals` approve the implementation plan

Execution should require:
- approved story
- approved current plan

---

## OpenSpec import mapping

This target model maps naturally from OpenSpec:

- capability → `Feature`
- requirement → `Story`
- `As a / I want / so that` → `Story.actor`, `intent`, `outcome`
- rule-level requirement sentence → `AcceptanceCriterion.statement`
- scenario → `Scenario`
- `proposal.md` → `Plan.summary`
- `design.md` → `Plan.design_notes`, `risks`, `assumptions`
- `tasks.md` → `Task` + `Subtask`

This avoids mixing durable product structure with archived change history.

---

## Historical implementation targets

## 1. Reset the foundational schema

Because existing data is disposable, prefer a clean reset over compatibility work.

### Tasks
- Historical target: Rewrite or replace the foundational migrations for stories, plans, tasks, and acceptance criteria.
- Historical target: Reintroduce plans as first-class records.
- Historical target: Move tasks back to `plan_id` ownership.
- Historical target: Add `stories.current_plan_id`.
- Historical target: Add the new `scenarios` table.
- Historical target: Add `stories.kind`, `actor`, `intent`, and `outcome`.
- Historical target: Rename acceptance criterion content from `criterion` to `statement`.
- Historical target: Rebuild any task-related FK assumptions around `plan_id` instead of `story_id`.

### Files affected
- `database/migrations/2026_04_29_050714_create_stories_table.php`
- `database/migrations/2026_04_29_050715_create_plans_table.php`
- `database/migrations/2026_04_29_050716_create_tasks_table.php`
- `database/migrations/2026_04_29_051134_create_acceptance_criteria_table.php`
- `database/migrations/2026_04_30_092722_drop_plan_attach_tasks_to_stories_add_subtasks.php`
- new `create_scenarios_table.php`

---

## 2. Add and refactor Eloquent models

### Story
- Historical target: Add fillable/casts for `kind`, `actor`, `intent`, `outcome`.
- Historical target: Add relations: `acceptanceCriteria`, `scenarios`, `plans`, `currentPlan`.
- Historical target: Remove assumptions that tasks belong directly to stories.
- Historical target: Route story task access through `currentPlan`.
- Historical target: Rewrite helper methods that traverse `story -> tasks` directly.

### AcceptanceCriterion
- Historical target: Rename payload field to `statement`.
- Historical target: Remove the assumption that criteria map 1:1 to tasks.
- Historical target: Add `scenarios()` and `tasks()` relations.
- Historical target: Remove or simplify implicit revision-bump hooks if they become misleading.

### Scenario
- Historical target: Create `app/Models/Scenario.php`.
- Historical target: Add relations to `Story`, optional `AcceptanceCriterion`, and optional `Task` links.

### Plan
- Historical target: Create or restore `app/Models/Plan.php`.
- Historical target: Add relations to `Story`, `Task`, and `PlanApproval`.

### Task
- Historical target: Replace `story()` with `plan()`.
- Historical target: Add optional `scenario()` relation.
- Historical target: Update dependency guards to compare `plan_id` rather than `story_id`.

### Subtask
- Historical target: Update traversal assumptions from `task->story` to `task->plan->story`.

### Files affected
- `app/Models/Story.php`
- `app/Models/AcceptanceCriterion.php`
- `app/Models/Task.php`
- `app/Models/Subtask.php`
- new `app/Models/Plan.php`
- new `app/Models/Scenario.php`

---

## 3. Add or update enums

- Historical target: Add `StoryKind` enum with `user_story`, `requirement`, `enabler`.
- Historical target: Add `PlanStatus` enum.
- Historical target: Add `PlanSource` enum.
- Historical target: Keep or later simplify existing `StoryStatus` and `FeatureStatus`.

### Files affected
- new `app/Enums/StoryKind.php`
- new `app/Enums/PlanStatus.php`
- new `app/Enums/PlanSource.php`
- `app/Enums/StoryStatus.php` if needed

---

## 4. Rewrite service-layer assumptions

## PlanWriter

Previous code assumed the story owned tasks directly. PR #49 changed this to `Task -> Plan -> Story`.

- Historical target: Rewrite `PlanWriter` so it writes tasks under a real `Plan`.
- Historical target: Decide whether plan replacement creates a new version or overwrites in place.
- Historical target: Prefer: create a new plan version, mark the old one `superseded`, and point `story.current_plan_id` at the new plan.

## Approval services

- Historical target: Keep story approval for product contract.
- Historical target: Add plan approval handling.
- Historical target: Ensure execution is gated by both story approval and plan approval.

## ExecutionService

- Historical target: Rewrite task traversal to use `story.currentPlan->tasks`.
- Historical target: Update next-actionable-subtask resolution.
- Historical target: Update retry and follow-up execution paths.
- Historical target: Update story completion logic to use current-plan tasks.

## GenerateTasksJob

- Historical target: Generate a plan, not just naked story tasks.
- Historical target: Attach tasks to `plan_id`.
- Historical target: Keep links to criteria and scenarios optional.

## SubtaskRunPipeline

- Historical target: Update story resolution paths from `task->story_id` to `task->plan->story_id`.
- Historical target: Keep append-proposed-subtasks logic aligned with plan-owned tasks.

## Pull request payloads / run surfaces

- Historical target: Recheck PR payload copy and execution assumptions.
- Historical target: Ensure any references to the temporary collapsed approval model reflect the new Story/Plan split.

### Files affected
- `app/Services/PlanWriter.php`
- `app/Services/ApprovalService.php`
- plan approval handling in `app/Services/ApprovalService.php`
- `app/Services/ExecutionService.php`
- `app/Services/SubtaskRunPipeline.php`
- `app/Services/PullRequests/PrPayloadBuilder.php`
- `app/Jobs/GenerateTasksJob.php`

---

## 5. Update MCP surfaces

The MCP server instructions and tool payloads need to reflect the new truth.

## Server instructions
- Historical target: Update `SpecifyServer` instructions to describe the hierarchy as feature → story → criteria/scenarios + plan → task → subtask.
- Historical target: Remove the claim that a Task is always one acceptance criterion.
- Historical target: Explicitly say Given / When / Then belongs in scenarios.

## Story tools
- Historical target: `CreateStoryTool`: accept `kind`, `actor`, `intent`, `outcome`.
- Historical target: `UpdateStoryTool`: same.
- Historical target: `GetStoryTool`: return framing fields, criteria, scenarios, and current plan summary.
- Historical target: `ListStoriesTool`: optionally include `kind` and framing summary.

## Acceptance criteria tools
- Historical target: Change `criterion` payloads to `statement`.
- Historical target: Update serializers and docs accordingly.

## Scenario tools
Add:
- Historical target: `CreateScenarioTool`
- Historical target: `UpdateScenarioTool`
- Historical target: `ListScenariosTool`
- Historical target: optional `DeleteScenarioTool`

## Plan tools
Add:
- Historical target: `CreatePlanTool`
- Historical target: `GetPlanTool`
- Historical target: `UpdatePlanTool`
- Historical target: `SetCurrentPlanTool`

## Task tools
- Historical target: Rewrite `SetTasksTool` so it writes through plans.
- Historical target: Return `plan_id` in task-plan responses.
- Historical target: Update `ListTasksTool`, `GetTaskTool`, and `UpdateTaskTool` to operate on plan-owned tasks.
- Historical target: Update `GenerateTasksTool` so it generates a plan.
- Historical target: Update `StartRunTool` so it requires an approved current plan.

### Files affected
- `routes/ai.php` (likely no structural change)
- `app/Mcp/Servers/SpecifyServer.php`
- `app/Mcp/Tools/CreateStoryTool.php`
- `app/Mcp/Tools/UpdateStoryTool.php`
- `app/Mcp/Tools/GetStoryTool.php`
- `app/Mcp/Tools/ListStoriesTool.php`
- `app/Mcp/Tools/AddAcceptanceCriterionTool.php`
- `app/Mcp/Tools/SetTasksTool.php`
- `app/Mcp/Tools/ListTasksTool.php`
- `app/Mcp/Tools/GetTaskTool.php`
- `app/Mcp/Tools/UpdateTaskTool.php`
- `app/Mcp/Tools/GenerateTasksTool.php`
- `app/Mcp/Tools/StartRunTool.php`
- new scenario tools
- new plan tools

---

## 6. Update web UI

## Story create page
- Historical target: Add story kind.
- Historical target: Add `actor`, `intent`, and `outcome` fields.
- Historical target: Keep acceptance criteria as short rule statements.
- Historical target: Do not encourage Given / When / Then inside acceptance-criteria inputs.

## Story show page
- Historical target: Add sections for story framing.
- Historical target: Add acceptance criteria section.
- Historical target: Add scenarios section.
- Historical target: Add current plan section.
- Historical target: Render tasks and subtasks under the current plan, not directly under the story.
- Historical target: Rework approval rail if plan approval is shown separately.

## Story index page
- Historical target: Update counts, eager loads, and summary chips to use current plan information.

## Task partials and links
- Historical target: Replace direct `$task->story` assumptions with `$task->plan->story`.

### Files affected
- `resources/views/pages/stories/⚡create.blade.php`
- `resources/views/pages/stories/⚡show.blade.php`
- `resources/views/pages/stories/⚡index.blade.php`
- `resources/views/partials/story-task.blade.php`
- `resources/views/partials/story-show/decision-rail.blade.php`

---

## 7. Factories and tests

## Factories
- Historical target: Update `StoryFactory` for `kind`, `actor`, `intent`, `outcome`.
- Historical target: Update `AcceptanceCriterionFactory` for `statement`.
- Historical target: Update `TaskFactory` for `plan_id` ownership.
- Historical target: Add `PlanFactory`.
- Historical target: Add `ScenarioFactory`.

## Feature tests
- Historical target: Story authoring supports structured framing.
- Historical target: Acceptance criteria remain atomic statements.
- Historical target: Scenarios can be created, updated, listed, and ordered.
- Historical target: Plans can be created and replaced cleanly.
- Historical target: Tasks execute through the current plan.
- Historical target: Execution requires an approved story and approved plan.

## MCP tests
- Historical target: Story tools accept and return the new framing fields.
- Historical target: Scenario tools work.
- Historical target: Task tools operate on plan-backed tasks.

## Existing tests likely affected
- `tests/Feature/SubtaskOrderingTest.php`
- `tests/Feature/ApprovalTest.php`
- `tests/Feature/StoryAuthoringTest.php`
- `tests/Feature/AgentRunRetryTest.php`
- `tests/Feature/AgentRunPrRetryTest.php`

---

## Suggested implementation order

### Phase 1 — schema reset
1. Rewrite foundational migrations or add one destructive reset migration.
2. Restore plans.
3. Add scenarios.
4. Move tasks back to plan ownership.
5. Add story framing fields and story kind.

### Phase 2 — models and enums
1. Update Story, AcceptanceCriterion, Task, Subtask.
2. Add Plan and Scenario.
3. Add StoryKind, PlanStatus, PlanSource.

### Phase 3 — services and jobs
1. Rewrite PlanWriter.
2. Split or extend approval handling.
3. Rewrite ExecutionService and GenerateTasksJob.
4. Fix PR/run traversal logic.

### Phase 4 — MCP
1. Update server instructions.
2. Update story and criterion tools.
3. Add scenario tools.
4. Add plan tools.
5. Rewrite task generation / execution tools.

### Phase 5 — UI
1. Story create.
2. Story show.
3. Story index.
4. Task partials and approvals rail.

### Phase 6 — tests
1. Rewrite assumptions around story-owned tasks.
2. Add coverage for scenarios and plans.
3. Verify MCP and execution flows.

---

## Recommended delivery approach

Because the system is still plastic, the cleanest path is:

1. create a branch
2. rewrite the foundational migrations to the target model
3. run `php artisan migrate:fresh --seed`
4. update models and services to the new truth
5. update MCP tools and UI
6. rewrite tests to match the target structure

This is preferable to layering compatibility logic on top of a model we already know is wrong.

---

## Summary

The redesign should intentionally:
- separate product contract from implementation plan
- give Given / When / Then a first-class home
- restore plans as first-class objects
- move tasks back under plans
- support structured story framing
- optimize for a clean long-term model rather than preserving today's shape
