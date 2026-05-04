# Restructure story and planning model

Related:
- GitHub issue: https://github.com/datashaman/specify/issues/47
- Specify feature: `Structured story and planning model`
- Specify story: `Add structured story framing, scenarios, and plans`

## Status

Proposed implementation plan.

Important constraint:
- Existing Specify data is **not sacred**.
- We do **not** need backward compatibility.
- Prefer the **cleanest target model** over compatibility bridges or migration shims.

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
- Do not preserve legacy names, compatibility aliases, duplicate ownership columns, or old traversal helpers.
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

## Implementation checklist

## 1. Reset the foundational schema

Because existing data is disposable, prefer a clean reset over compatibility work.

### Tasks
- [ ] Rewrite or replace the foundational migrations for stories, plans, tasks, and acceptance criteria.
- [ ] Reintroduce plans as first-class records.
- [ ] Move tasks back to `plan_id` ownership.
- [ ] Add `stories.current_plan_id`.
- [ ] Add the new `scenarios` table.
- [ ] Add `stories.kind`, `actor`, `intent`, and `outcome`.
- [ ] Rename acceptance criterion content from `criterion` to `statement`.
- [ ] Rebuild any task-related FK assumptions around `plan_id` instead of `story_id`.

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
- [ ] Add fillable/casts for `kind`, `actor`, `intent`, `outcome`.
- [ ] Add relations: `acceptanceCriteria`, `scenarios`, `plans`, `currentPlan`.
- [ ] Remove assumptions that tasks belong directly to stories.
- [ ] Route story task access through `currentPlan`.
- [ ] Rewrite helper methods that traverse `story -> tasks` directly.

### AcceptanceCriterion
- [ ] Rename payload field to `statement`.
- [ ] Remove the assumption that criteria map 1:1 to tasks.
- [ ] Add `scenarios()` and `tasks()` relations.
- [ ] Remove or simplify implicit revision-bump hooks if they become misleading.

### Scenario
- [ ] Create `app/Models/Scenario.php`.
- [ ] Add relations to `Story`, optional `AcceptanceCriterion`, and optional `Task` links.

### Plan
- [ ] Create or restore `app/Models/Plan.php`.
- [ ] Add relations to `Story`, `Task`, and `PlanApproval`.

### Task
- [ ] Replace `story()` with `plan()`.
- [ ] Add optional `scenario()` relation.
- [ ] Update dependency guards to compare `plan_id` rather than `story_id`.

### Subtask
- [ ] Update traversal assumptions from `task->story` to `task->plan->story`.

### Files affected
- `app/Models/Story.php`
- `app/Models/AcceptanceCriterion.php`
- `app/Models/Task.php`
- `app/Models/Subtask.php`
- new `app/Models/Plan.php`
- new `app/Models/Scenario.php`

---

## 3. Add or update enums

- [ ] Add `StoryKind` enum with `user_story`, `requirement`, `enabler`.
- [ ] Add `PlanStatus` enum.
- [ ] Add `PlanSource` enum.
- [ ] Keep or later simplify existing `StoryStatus` and `FeatureStatus`.

### Files affected
- new `app/Enums/StoryKind.php`
- new `app/Enums/PlanStatus.php`
- new `app/Enums/PlanSource.php`
- `app/Enums/StoryStatus.php` if needed

---

## 4. Rewrite service-layer assumptions

## PlanWriter

Current code assumes the story owns tasks directly. That should change.

- [ ] Rewrite `PlanWriter` so it writes tasks under a real `Plan`.
- [ ] Decide whether plan replacement creates a new version or overwrites in place.
- [ ] Prefer: create a new plan version, mark the old one `superseded`, and point `story.current_plan_id` at the new plan.

## Approval services

- [ ] Keep story approval for product contract.
- [ ] Add a separate `PlanApprovalService` for plan approval.
- [ ] Ensure execution is gated by both story approval and plan approval.

## ExecutionService

- [ ] Rewrite task traversal to use `story.currentPlan->tasks`.
- [ ] Update next-actionable-subtask resolution.
- [ ] Update retry and follow-up execution paths.
- [ ] Update story completion logic to use current-plan tasks.

## GenerateTasksJob

- [ ] Generate a plan, not just naked story tasks.
- [ ] Attach tasks to `plan_id`.
- [ ] Keep links to criteria and scenarios optional.

## SubtaskRunPipeline

- [ ] Update story resolution paths from `task->story_id` to `task->plan->story_id`.
- [ ] Keep append-proposed-subtasks logic aligned with plan-owned tasks.

## Pull request payloads / run surfaces

- [ ] Recheck PR payload copy and execution assumptions.
- [ ] Ensure any references to story-only approval reflect the new story/plan split.

### Files affected
- `app/Services/PlanWriter.php`
- `app/Services/ApprovalService.php`
- new `app/Services/PlanApprovalService.php`
- `app/Services/ExecutionService.php`
- `app/Services/SubtaskRunPipeline.php`
- `app/Services/PullRequests/PrPayloadBuilder.php`
- `app/Jobs/GenerateTasksJob.php`

---

## 5. Update MCP surfaces

The MCP server instructions and tool payloads need to reflect the new truth.

## Server instructions
- [ ] Update `SpecifyServer` instructions to describe the hierarchy as feature → story → criteria/scenarios + plan → task → subtask.
- [ ] Remove the claim that a task is always one acceptance criterion.
- [ ] Explicitly say Given / When / Then belongs in scenarios.

## Story tools
- [ ] `CreateStoryTool`: accept `kind`, `actor`, `intent`, `outcome`.
- [ ] `UpdateStoryTool`: same.
- [ ] `GetStoryTool`: return framing fields, criteria, scenarios, and current plan summary.
- [ ] `ListStoriesTool`: optionally include `kind` and framing summary.

## Acceptance criteria tools
- [ ] Change `criterion` payloads to `statement`.
- [ ] Update serializers and docs accordingly.

## Scenario tools
Add:
- [ ] `CreateScenarioTool`
- [ ] `UpdateScenarioTool`
- [ ] `ListScenariosTool`
- [ ] optional `DeleteScenarioTool`

## Plan tools
Add:
- [ ] `CreatePlanTool`
- [ ] `GetPlanTool`
- [ ] `UpdatePlanTool`
- [ ] `SetCurrentPlanTool`

## Task tools
- [ ] Rewrite `SetTasksTool` so it writes through plans.
- [ ] Return `plan_id` in task-plan responses.
- [ ] Update `ListTasksTool`, `GetTaskTool`, and `UpdateTaskTool` to operate on plan-owned tasks.
- [ ] Update `GenerateTasksTool` so it generates a plan.
- [ ] Update `StartRunTool` so it requires an approved current plan.

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
- [ ] Add story kind.
- [ ] Add `actor`, `intent`, and `outcome` fields.
- [ ] Keep acceptance criteria as short rule statements.
- [ ] Do not encourage Given / When / Then inside acceptance-criteria inputs.

## Story show page
- [ ] Add sections for story framing.
- [ ] Add acceptance criteria section.
- [ ] Add scenarios section.
- [ ] Add current plan section.
- [ ] Render tasks and subtasks under the current plan, not directly under the story.
- [ ] Rework approval rail if plan approval is shown separately.

## Story index page
- [ ] Update counts, eager loads, and summary chips to use current plan information.

## Task partials and links
- [ ] Replace direct `$task->story` assumptions with `$task->plan->story`.

### Files affected
- `resources/views/pages/stories/⚡create.blade.php`
- `resources/views/pages/stories/⚡show.blade.php`
- `resources/views/pages/stories/⚡index.blade.php`
- `resources/views/partials/story-task.blade.php`
- `resources/views/partials/story-show/decision-rail.blade.php`

---

## 7. Factories and tests

## Factories
- [ ] Update `StoryFactory` for `kind`, `actor`, `intent`, `outcome`.
- [ ] Update `AcceptanceCriterionFactory` for `statement`.
- [ ] Update `TaskFactory` for `plan_id` ownership.
- [ ] Add `PlanFactory`.
- [ ] Add `ScenarioFactory`.

## Feature tests
- [ ] Story authoring supports structured framing.
- [ ] Acceptance criteria remain atomic statements.
- [ ] Scenarios can be created, updated, listed, and ordered.
- [ ] Plans can be created and replaced cleanly.
- [ ] Tasks execute through the current plan.
- [ ] Execution requires an approved story and approved plan.

## MCP tests
- [ ] Story tools accept and return the new framing fields.
- [ ] Scenario tools work.
- [ ] Task tools operate on plan-backed tasks.

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
