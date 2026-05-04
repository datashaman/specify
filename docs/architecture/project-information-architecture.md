# Project information architecture

Specify is organised around Projects. Workspace and Team provide tenancy and
membership; Project is the persistent product context; Feature, Story, Plan,
Task, Subtask, Repo, and AgentRun hang from that context.

This page describes the current implementation shape. The load-bearing
decision is [ADR-0012](../adr/0012-project-first-information-architecture.md).
The Story and execution details are covered by
[Story planning model](story-planning-model.md) and
[AgentRun lifecycle](agent-run-lifecycle.md).

## Domain shape

```text
Workspace
  -> Team
    -> Project
      -> Feature
        -> Story
          -> Plan
            -> Task
              -> Subtask

Workspace
  -> Repo

Project
  -> Repo via project_repo
```

The main ownership rules:

- `Workspace` is the tenant boundary. It owns Teams and Repos directly.
- `Team` is the membership and role boundary inside a Workspace.
- `Project` is owned by a Team and contains Features.
- `Feature` contains Stories.
- `Story` owns product-contract children and Plan history.
- `Plan` owns Tasks; Tasks own Subtasks.
- `Repo` is workspace-scoped and can attach to one or more Projects through
  `project_repo`.

Project context is resolved by following the domain chain:

| Starting point | Project resolution |
|---|---|
| `Feature` | `Feature -> Project` |
| `Story` | `Story -> Feature -> Project` |
| `Plan` | `Plan -> Story -> Feature -> Project` |
| `Task` | `Task -> Plan -> Story -> Feature -> Project` |
| `Subtask` | `Subtask -> Task -> Plan -> Story -> Feature -> Project` |
| `AgentRun` for a Story | `AgentRun -> Story -> Feature -> Project` |
| `AgentRun` for a Subtask | `AgentRun -> Subtask -> Task -> Plan -> Story -> Feature -> Project` |

Do not add shortcut foreign keys to avoid these joins unless the model is
deliberately denormalised and documented. In particular, Tasks do not point
directly at Stories or Projects.

## Workspace and Team

`Workspace` is ambient context rather than a product navigation target. New
users are bootstrapped with a personal Workspace and Team during auth setup.

`Team` carries membership through the `team_user` pivot. The pivot stores
`TeamRole`, and those roles drive project management and approval authority.

Current role checks:

- `User::accessibleProjectIds()` returns Projects owned by Teams the user
  belongs to.
- `User::canApproveInProject()` allows `Owner` and `Admin` roles.
- Project, Feature, Story, Plan, Task, Subtask, Run, and Repo pages reject
  inaccessible Project IDs with a 404.
- Active-project state is only a UI convenience; it does not grant access.

## Project context

`users.current_project_id` stores the active Project. The URL remains the
source of truth for project-scoped pages.

Current write paths:

- Project-scoped Livewire pages accept `{project}` in `mount()` and verify
  access.
- Several high-traffic Project pages mirror the route Project into
  `users.current_project_id` so the sidebar and MCP defaults stay aligned with
  navigation.
- The app switcher calls `User::switchProject()` and navigates to the selected
  Project overview.
- `User::switchWorkspace()` changes `current_team_id` and clears
  `current_project_id`.
- MCP tools default to `current_project_id` when a `project_id` argument is
  optional.

Current read paths:

- The sidebar reads `currentWorkspace()` and `currentProject`.
- Project lists use `accessibleProjectsInCurrentWorkspace()`.
- Project-scoped pages use the URL Project parameter and access checks.
- Workspace-scoped pages use `scopedProjectIds()` when they need the active
  Project filter, or `accessibleProjectIds()` when they intentionally span all
  accessible Projects.

The app switcher currently allows an "All projects" state by setting
`current_project_id` to null. Project-scoped pages still require an explicit
Project in the URL.

## Web routes

Workspace-scoped routes:

| Route | Purpose |
|---|---|
| `/triage` | Cross-project approval queue. |
| `/activity` | Cross-project activity stream. |
| `/projects` | Project picker/list. |

Project-scoped routes:

| Route | Purpose |
|---|---|
| `/projects/{project}` | Project overview and Feature management. |
| `/projects/{project}/features/{feature}` | Feature document. |
| `/projects/{project}/stories` | Project Story list. |
| `/projects/{project}/stories/create` | Create a Story under the Project. |
| `/projects/{project}/stories/{story}` | Story document. |
| `/projects/{project}/plans` | Project Plan list. |
| `/projects/{project}/plans/{plan}` | Plan document. |
| `/projects/{project}/approvals` | Project approval view. |
| `/projects/{project}/runs` | Project run list. |
| `/projects/{project}/repos` | Project repository management. |
| `/projects/{project}/stories/{story}/tasks/{task}` | Task document. |
| `/projects/{project}/stories/{story}/subtasks/{subtask}` | Subtask document. |
| `/projects/{project}/stories/{story}/subtasks/{subtask}/runs/{run}` | Run console. |

Current exception:

- `/runs/{run}/events` is a polling endpoint for run progress events. It is
  not a navigation route. `RunEventsController` authorises it by resolving the
  `AgentRun` back to its Story or Subtask Project and checking
  `accessibleProjectIds()`.

Flat record-page routes such as `/stories/{id}` and `/runs/{id}` are not part
of the current web product surface.

## Livewire page contract

Project-owned pages follow the same route contract:

1. accept the Project route parameter
2. check that the authenticated user can access it
3. store the Project ID locally for queries and route generation
4. ensure child route parameters belong to that Project

Pages that represent a user's current working context should also mirror the
route Project into `users.current_project_id`. The mirror is not required for
authorisation; it keeps ambient UI and MCP defaults aligned with the page the
user is viewing.

Examples:

| Page | Route binding check |
|---|---|
| `pages::projects.show` | Project ID must be accessible. |
| `pages::features.show` | Feature must belong to the route Project. |
| `pages::stories.index` | Project ID must be accessible. |
| `pages::stories.show` | Story must be accessible and, when supplied, belong to the route Project. |
| `pages::runs.show` | Run must resolve through the route Project, Story, and Subtask. |
| `pages::repos.index` | Project ID must be accessible before Repo management. |

Queries should prefer the route Project over ambient user state on
project-scoped pages. Ambient state is useful for defaults and sidebar chrome,
not for authorisation.

## Repositories

Repos are workspace-scoped because a single git repository can be shared across
Projects in the same Workspace. Projects attach Repos through `project_repo`.

`project_repo` stores:

- `project_id`
- `repo_id`
- `role`
- `is_primary`

`Project::attachRepo()` preserves the same-workspace invariant and chooses a
primary Repo automatically when the Project has no existing primary. Passing
`primary=true` or calling `setPrimaryRepo()` clears the previous primary for
that Project.

The primary Repo is the default execution Repo:

```text
Subtask -> Task -> Plan -> Story -> Feature -> Project -> primaryRepo()
```

Repo provider selection:

| Provider surface | Selection point |
|---|---|
| Pull requests | `Repo::pullRequestProvider()` |
| Advisory reviews | `Repo::reviewProvider()` |
| Webhooks | GitHub webhook route keyed by Repo ID |

Removing a Repo from a Project currently detaches the Repo from Projects and
deletes the Repo row after deleting the GitHub webhook when applicable.

## MCP surface

MCP tools use Project context in two ways:

- explicit `project_id` arguments for project-scoped list/create/manage tools
- defaulting to the user's current Project when `project_id` is optional

`CurrentContextTool` returns acting user, current Workspace, current Team, and
current Project. Tools that resolve Project-owned records use
`ResolvesProjectAccess` so Story, Feature, Plan, Scenario, Task, and Subtask
lookups all enforce the same access rule.

Common MCP patterns:

| Tool shape | Project behaviour |
|---|---|
| `list-projects` | Lists all accessible Projects. |
| `switch-project` | Sets `users.current_project_id`. |
| `list-features`, `list-stories`, `list-runs`, `list-repos` | Use explicit `project_id` or current Project. |
| `create-feature`, `create-story`, `create-project` | Create records under the selected Project or Team. |
| `add-github-repo-to-project`, `set-primary-repo`, `remove-project-repo` | Manage Project -> Repo attachment with approval-role checks. |
| Story / Plan / Task / Subtask tools | Resolve access through the owning Project. |

The MCP surface returns IDs and data, not canonical web URLs. Clients can build
URLs from the Project, Story, Subtask, and Run IDs when needed.

## Invariants to preserve

- Do not make Workspace a primary product navigation target.
- Do not add flat record-page routes for Project-owned records.
- Do not trust `current_project_id` for authorisation.
- Do not query Project-owned data from ambient state when the URL supplies a
  Project.
- Do not attach a Repo to a Project in a different Workspace.
- Do not set more than one primary Repo for a Project.
- Do not create direct Task -> Story or Task -> Project ownership shortcuts.
- Do not bypass `ResolvesProjectAccess` in MCP tools that touch
  Project-owned records.
- Do not expose run events without resolving the run back to an accessible
  Project.
