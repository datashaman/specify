# MCP surface

Specify exposes its planning and execution workflow through a Laravel MCP
server. The MCP surface uses the same product and delivery language as the web
app: Project, Feature, Story, AcceptanceCriterion, Scenario, Plan, Task,
Subtask, Repo, and AgentRun.

This page describes the current implementation shape. Related pages:
[Project information architecture](project-information-architecture.md),
[Story planning model](story-planning-model.md),
[Approval architecture](approval-architecture.md),
[AgentRun lifecycle](agent-run-lifecycle.md), and
[Repository integration](repository-integration.md).

## Server shape

`App\Mcp\Servers\SpecifyServer` registers tools only. It currently exposes no
MCP resources and no MCP prompts.

The server instructions are part of the contract. They tell agents:

- Project -> Feature -> Story -> AcceptanceCriterion / Scenario -> Plan ->
  Task -> Subtask is the hierarchy.
- Feature and Story are product language, not implementation detail.
- Acceptance criteria are atomic observable rules.
- Scenarios hold Given / When / Then examples.
- Plan, Task, and Subtask are delivery language.
- Story approval gates product contract; current Plan approval gates execution.
- Implementation details belong in Plans, Tasks, and Subtasks, not Features or
  Stories.

Tool classes follow the same local shape:

- `#[Description(...)]` explains what the tool does.
- `protected string $name` defines the MCP tool name.
- `handle()` resolves the acting user, validates input, performs the operation,
  and returns `Response::json()` or `Response::error()`.
- `schema()` describes accepted arguments.

## Authentication

`App\Mcp\Auth::resolve()` resolves the acting user from the MCP request. When
the request has no authenticated user, it falls back to
`specify.mcp.user_email`.

Tools should not reach around this helper. Tools that need a user should call
`resolveUser()` from `ResolvesProjectAccess`, which returns either a `User` or
a `Response::error('Authentication required.')`.

GitHub repo tools use the acting user's stored GitHub OAuth token. Repo catalog
lookups go through `GithubRepoCatalog`, which caches the user's accessible
GitHub repos for five minutes.

## Access control

Most tools are project-scoped. `ResolvesProjectAccess` centralises access
checks for:

- Project
- Feature
- Story
- Scenario
- Plan

Tools that start from Task, Subtask, AgentRun, Repo, or WebhookEvent resolve
the owning Project or attached Projects manually, then verify access through
`canAccessProject()` or the same `accessibleProjectIds()` set.

Approval and repo-management tools add Owner/Admin role checks:

- approval decisions require `User::canApproveInProject()` for the owning Project
- repo management currently uses the same `User::canApproveInProject()` check
- project creation requires Owner or Admin in the selected Team

Current role authority comes from `User::canApproveInProject()`, which allows
Team `Owner` and `Admin`.

## Project context

Many tools accept an optional `project_id`. When omitted, they default to the
acting user's `current_project_id`.

Tools that default this way must return a clear error when neither an explicit
Project nor a current Project is available.

Project context tools:

| Tool | Purpose |
|---|---|
| `current-context` | Returns acting user, current Workspace, current Team, and current Project. |
| `list-projects` | Lists accessible Projects and marks the current Project. |
| `switch-project` | Validates access and writes `users.current_project_id`. |
| `create-project` | Creates a Project under a Team, optionally attaches GitHub Repos, and switches current Project to the new Project. |

## Tool groups

Project and Feature tools:

- `get-project`
- `list-projects`
- `create-project`
- `switch-project`
- `list-features`
- `get-feature`
- `create-feature`
- `update-feature`

Story product-contract tools:

- `list-stories`
- `get-story`
- `create-story`
- `update-story`
- `add-acceptance-criterion`
- `list-scenarios`
- `create-scenario`
- `update-scenario`
- `add-story-dependency`

Plan and delivery tools:

- `create-plan`
- `get-plan`
- `list-plans`
- `update-plan`
- `set-current-plan`
- `set-tasks`
- `list-tasks`
- `get-task`
- `update-task`
- `update-subtask`

Approval tools:

- `submit-story`
- `approve-story`
- `request-story-changes`
- `reject-story`
- `submit-plan`
- `approve-plan`
- `request-plan-changes`
- `reject-plan`

Execution and observation tools:

- `generate-tasks`
- `start-run`
- `list-runs`
- `get-run`
- `list-activity`

Repo tools:

- `add-github-repo-to-project`
- `list-repos`
- `get-repo`
- `set-primary-repo`
- `remove-project-repo`

## Product contract writes

Story tools write product contract data only. They should not smuggle
implementation details into Feature or Story descriptions.

Product contract changes go through Story writer services:

- `StoryWriter`
- `AcceptanceCriteriaWriter`
- `ScenarioWriter`
- `StoryRevisionLifecycle`

Those services own revision bumps and approval reopening. MCP tools should not
hand-edit Story contract rows in a way that bypasses the lifecycle.

Acceptance criteria and scenarios are separate surfaces:

- acceptance criteria are short rule statements
- scenarios are Given / When / Then examples

Do not collapse scenarios into acceptance-criterion strings.

## Plan writes

`set-tasks` replaces the Story's current implementation Plan in one
transaction. It routes through `PlanWriter::replacePlan()`, which writes the
fresh Plan, Tasks, and Subtasks and owns current-Plan semantics.

Plan replacement behavior:

- Tasks belong to the new Plan.
- Subtasks belong to Tasks.
- Tasks may link to acceptance criteria and scenarios from the same Story.
- Tasks may declare dependencies by Task position.
- Approved Stories receive a new current Plan that starts pending approval.
- Non-approved Stories receive a draft Plan.

Task and Subtask update tools reopen Plan approval when structural fields
change. They do not reopen Story approval unless the product contract changes.

## Approvals

Approval tools use `RecordsApprovalDecisions` plus
`ResolvesProjectAccess`.

Story approval tools act on the Story product contract. Plan approval tools act
only on the Story's current Plan.

Plan approval tools must not accept decisions for superseded or non-current
Plans. The approval service and lifecycle enforce this; MCP tools should keep
the same constraint in their resolver path.

Approval tool responses return the approval row ID and refreshed target status.
That is enough for clients to continue the workflow without re-fetching the
entire Story or Plan.

## Execution tools

`generate-tasks` creates a new current implementation Plan for an approved
Story when the Story does not already have Tasks in its current Plan. Generated
Plans reopen Plan approval so humans can review the breakdown before execution.

`start-run` starts or resumes execution for a Story whose product contract and
current Plan are both approved. It calls `ExecutionService::startStoryExecution()`.

Run lookup tools:

- `list-runs` requires at least one of `story_id`, `task_id`, or `subtask_id`
- `get-run` resolves Project access from the AgentRun runnable
- `get-run` omits the full diff unless `include_diff=true`

MCP does not expose direct cancellation or retry tools currently. Those
surfaces exist in the web run console and `ExecutionService`.

## Repo and activity tools

Repo tools mirror the Project Repos page behavior.

`add-github-repo-to-project`:

- defaults to current Project when `project_id` is omitted
- requires a connected GitHub OAuth session
- validates the repo against the user's GitHub catalog
- creates or reuses a workspace Repo
- attempts webhook installation
- attaches the Repo to the Project

`list-activity` reads webhook delivery rows. It requires `repo_id` or
`project_id`; there is no unscoped activity dump.

## Invariants to preserve

- Do not add MCP-only domain language that differs from the web app and ADRs.
- Do not let MCP tools bypass Project access checks.
- Do not let MCP approval tools bypass approver-role checks.
- Do not write Story product-contract data outside the Story writer and
  revision lifecycle seams.
- Do not replace Plans outside `PlanWriter::replacePlan()`.
- Do not create Tasks directly under Stories.
- Do not start execution without both an approved Story and approved current
  Plan.
- Do not return full run diffs by default from `get-run`.
- Do not expose workspace-wide activity without a Repo or Project filter.
