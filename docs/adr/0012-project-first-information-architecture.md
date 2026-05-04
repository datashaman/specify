# 0012. Project-first information architecture

Date: 2026-05-02
Status: Accepted

## Context

The shell that grew alongside the early features is flat: every record-type — Features, Stories, Runs, Repos, Events, Inbox — sits at the workspace level in the sidebar, and the URLs that back them are unscoped (`/stories/{id}`, `/runs/{id}`, `/features`). That worked while the system carried one project; it does not work now.

Concrete failures observed in the live shell (2026-05-02 visual smoke):

- **No project context is ever visible**. Switching projects in the workspace switcher updates the current project pin but the rest of the UI doesn't reflect it — the Stories list is workspace-wide, the Runs list is workspace-wide, breadcrumbs lead back to the cross-project approval queue rather than the parent Feature / Project.
- **`/stories/{id}` cannot be parsed at a glance**. The URL gives no hint of which Project or Feature the Story belongs to, so notification deep-links and PR descriptions force the reader to open the page to orient.
- **Repos sit at the wrong level** — they're attached to Projects (per ADR-0006's project-repo wiring), but the sidebar lists them as a workspace concern.
- **The triage role and the project role share one nav surface** — a reviewer clearing the cross-project queue and an engineer working a single project are looking at the same flat list and the same flat URLs.
- **Feature has no document of its own**. The route `features.show` exists but renders a thin list; Stories' rail/pill/decision chrome doesn't roll up to the Feature.

The grilling that produced the UI design brief (2026-05-02) settled five branches:

1. Workspace is **ambient chrome** — switcher only, never a navigation target. (Q1.)
2. The **project** is the persistent context. The active project drives every project-scoped page.
3. **Triage** is **workspace-wide** — a cross-project reviewer queue.
4. **Activity** replaces "Events" as the cross-project event stream.
5. **Run console URL** is nested: `/projects/{p}/stories/{s}/subtasks/{st}/runs/{r}`. The brief recorded this but the routes never landed.

This ADR records the IA decision so the slice-by-slice migration that follows has a single load-bearing reference.

## Decision

**The shell uses two-section sidebar navigation: a workspace-scoped section (Triage, Activity) and a project-scoped section bound to a persisted active project. All record-type listings, the Story document, and the run console live under `/projects/{p}/...`. Flat record routes are not part of the product surface.**

Concrete shape:

### Sidebar

```
Sidebar
─────────────────────────────────
[Workspace ▾]                    ← ambient: switcher only
─────────────────────────────────
Triage          ← cross-project approval queue
Activity        ← cross-project event stream
─────────────────────────────────
[Project ▾]                      ← own dropdown; persistent active project
  Overview
  Features
  Stories
  Runs
  Repos
─────────────────────────────────
[User ▾]
```

The workspace switcher and the project switcher are **separate dropdowns**. They are not nested — switching workspace clears the active project and shows a project picker; switching project preserves the workspace.

### Active-project state

The active project is persisted in the existing `users.current_project_id`
column and read directly per request. The existing `User` model exposes the
column.

- **Read path**: `auth()->user()->current_project_id` — direct column read.
- **Write path**: every project-scoped page's `mount(int $project)` validates the URL param against `accessibleProjectIds()` and pins the column to match (see `pages/stories/⚡show.blade.php`, `pages/stories/⚡index.blade.php`, `pages/runs/⚡index.blade.php`, `pages/repos/⚡index.blade.php`, `pages/subtasks/⚡show.blade.php`, `pages/runs/⚡show.blade.php`). The URL is the source of truth; the column mirrors the URL so the sidebar and other workspace-scoped pages stay in sync.
- **Switcher**: `livewire/app-switcher` writes the column via `User::switchProject()` and navigates back to the referer.
- **First login**: no special handling — the column is null until the user picks a project. The sidebar's Project section collapses to a single "Pick a project" link in that state.
- **Authorisation**: every project-scoped page mount aborts 404 when the URL param isn't in `accessibleProjectIds()`. Active-project state is a UI affordance, not a permission grant.

### Routes

| Workspace-scoped | Purpose |
|---|---|
| `/triage` | cross-project approval queue |
| `/activity` | cross-project event stream |

| Project-scoped | Purpose |
|---|---|
| `/projects/{project}` | project overview (in-flight stories, recent runs, blockers) |
| `/projects/{project}/features/{feature}` | feature page (status pill rolls up child stories) |
| `/projects/{project}/stories` | cross-feature story list for the project |
| `/projects/{project}/stories/{story}` | Story document (canonical) |
| `/projects/{project}/stories/{story}/subtasks/{subtask}` | subtask drawer |
| `/projects/{project}/stories/{story}/subtasks/{subtask}/runs/{run}` | run console |
| `/projects/{project}/runs` | recent runs for this project |
| `/projects/{project}/repos` | repos connected to this project |

### Route component layout

Project-scoped routes are declared explicitly in `routes/web.php` and point at
the existing Volt page components:

```
pages/
├── ⚡triage.blade.php
├── activity/
│   └── ⚡index.blade.php
├── projects/
│   ├── ⚡index.blade.php
│   └── ⚡show.blade.php
├── features/
│   └── ⚡show.blade.php
├── stories/
│   ├── ⚡create.blade.php
│   ├── ⚡index.blade.php
│   └── ⚡show.blade.php
├── plans/
│   ├── ⚡index.blade.php
│   └── ⚡show.blade.php
├── approvals/
│   └── ⚡index.blade.php
├── tasks/
│   └── ⚡show.blade.php
├── subtasks/
│   └── ⚡show.blade.php
├── runs/
│   ├── ⚡index.blade.php
│   └── ⚡show.blade.php
└── repos/
    └── ⚡index.blade.php
```

## Consequences

### Positive

- **Project context is visible everywhere** — the URL, the breadcrumb, and the sidebar all carry the active project. Switching project moves the whole UI atomically.
- **Triage role is separated from project role**. A reviewer working the cross-project queue and an engineer working a single project see distinct nav and distinct URLs.
- **Deep-links are self-describing** — `/projects/{project}/stories/{story}` orients the reader without opening the page.
- **Repos and Runs are scoped correctly** — they live under the project that owns them, not the workspace.
- **The Feature finally has its own document**, with the same rail/pill chrome the Story uses; rolling tallies make Feature-level state visible at a glance.
- **The run-console URL contract is explicit** — run details live under the owning Project, Story, and Subtask.

### Negative

- **Volume of route-aware page work** — every project-owned page must validate the project URL parameter and keep `users.current_project_id` in sync.
- **Breadcrumbs become page chrome** — every page renders `{Project} › {Section} [› {Subsection}]`, so the breadcrumb component must be a first-class layout primitive rather than a per-page snippet.

### Neutral

- **ADR-0001 unchanged** — Story and current Plan remain the approval gates; the IA change is shell-level.
- **ADR-0006 unchanged** — multi-executor race mode is per-Subtask; the run console URL nests it under Subtask cleanly.
- **MCP tools unchanged** — the MCP tool surface (`get-story`, `list-runs`, etc.) returns IDs, not URLs; the client stitches URLs from the active context. The IA change ripples through the web shell only.

## Closed questions

- **Post-auth landing** — `/projects` is the project picker; `/projects/{project}` is the project overview.
- **Flat record URLs** — removed. Record pages require canonical project-scoped URLs.
- **Breadcrumb for Triage / Activity** — render as just `Triage` / `Activity`; the workspace is ambient.
- **MCP `current-context` tool** — returns both active project and workspace context.
