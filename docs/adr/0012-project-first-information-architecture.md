# 0012. Project-first information architecture

Date: 2026-05-02
Status: Proposed

## Context

The shell that grew alongside the early features is flat: every record-type — Features, Stories, Runs, Repos, Events, Inbox — sits at the workspace level in the sidebar, and the URLs that back them are unscoped (`/stories/{id}`, `/runs/{id}`, `/features`). That worked while the system carried one project; it does not work now.

Concrete failures observed in the live shell (2026-05-02 visual smoke):

- **No project context is ever visible**. Switching projects in the workspace switcher updates a session value but the rest of the UI doesn't reflect it — the Stories list is workspace-wide, the Runs list is workspace-wide, breadcrumbs lead back to "Approval inbox" rather than the parent Feature / Project.
- **`/stories/{id}` cannot be parsed at a glance**. The URL gives no hint of which Project or Feature the Story belongs to, so notification deep-links and PR descriptions force the reader to open the page to orient.
- **Repos sit at the wrong level** — they're attached to Projects (per ADR-0006's project-repo wiring), but the sidebar lists them as a workspace concern.
- **The triage role and the project role share one nav surface** — a reviewer clearing the cross-project queue and an engineer working a single project are looking at the same flat list and the same flat URLs.
- **Feature has no document of its own**. The route `features.show` exists but renders a thin list; Stories' rail/pill/decision chrome doesn't roll up to the Feature.

The grilling that produced the UI design brief (2026-05-02) settled five branches:

1. Workspace is **ambient chrome** — switcher only, never a navigation target. (Q1.)
2. The **project** is the persistent context. The active project drives every project-scoped page.
3. **Triage** is **workspace-wide** — a cross-project reviewer queue. (renamed from "Approval inbox".)
4. **Activity** replaces "Events" as the cross-project event stream.
5. **Run console URL** is nested: `/projects/{p}/stories/{s}/subtasks/{st}/runs/{r}`. The brief recorded this but the routes never landed.

This ADR records the IA decision so the slice-by-slice migration that follows has a single load-bearing reference.

## Decision

**The shell uses two-section sidebar navigation: a workspace-scoped section (Triage, Activity) and a project-scoped section bound to a session-persisted active project. All record-type listings, the Story document, and the run console live under `/projects/{p}/...`. The legacy flat URLs 301 to their nested equivalents.**

Concrete shape:

### Sidebar

```
Sidebar
─────────────────────────────────
[Workspace ▾]                    ← ambient: switcher only
─────────────────────────────────
Triage          ← cross-project approval queue (renamed from Inbox)
Activity        ← cross-project event stream (renamed from Events)
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

A single new column carries the persistence:

```php
Schema::table('users', function (Blueprint $t) {
    $t->foreignId('active_project_id')->nullable()->constrained('projects')->nullOnDelete();
});
```

- **Read path**: `session('active_project_id')`. Cheap, request-local; no DB hit per request.
- **Write path**: switching project writes both `session()` and `users.active_project_id`. The DB column is the cross-device fallback hydrated on login.
- **First login**: pick the first project the user can access. If they have zero, render a project picker (no project menu shown).
- **Stale session**: if the session id no longer matches a project the user can access, fall back to the column; if that's also stale, fall back to "first accessible".
- **Authorisation**: every project-scoped route must `abort_unless($user->canAccessProject($project), 404)`. Active-project state is a UI affordance, not a permission grant.

### Routes

| Workspace-scoped | Purpose |
|---|---|
| `/triage` | cross-project approval queue (renamed from `/inbox`) |
| `/activity` | cross-project event stream (renamed from `/events`) |

| Project-scoped | Purpose |
|---|---|
| `/projects/{project}` | project overview (in-flight stories, recent runs, blockers) |
| `/projects/{project}/features` | feature list |
| `/projects/{project}/features/{feature}` | feature page (status pill rolls up child stories) |
| `/projects/{project}/stories` | cross-feature story list for the project |
| `/projects/{project}/stories/{story}` | Story document (canonical) |
| `/projects/{project}/stories/{story}/subtasks/{subtask}` | subtask drawer |
| `/projects/{project}/stories/{story}/subtasks/{subtask}/runs/{run}` | run console |
| `/projects/{project}/runs` | recent runs for this project |
| `/projects/{project}/repos` | repos connected to this project |

### Legacy URL handling

| Legacy | Behaviour |
|---|---|
| `/inbox` | 301 → `/triage` |
| `/events` | 301 → `/activity` |
| `/stories/{id}` | 301 → `/projects/{p}/stories/{s}` (resolve `p` from `story.feature.project_id`) |
| `/runs/{id}` | 301 → `/projects/{p}/stories/{s}/subtasks/{st}/runs/{r}` (resolve via `agent_run.runnable`); for plan-generation runs (Story-runnable) → `/projects/{p}/stories/{s}` |
| `/features`, `/features/{p}/{f}` | 301 → project-scoped equivalent if active project resolves; 404 otherwise |
| `/dashboard` | retired; 301 → `/projects/{p}` |

The redirect map lives in **one place** — a single `RedirectController` (or `routes/web.php` block). PR descriptions, Slack links, and outbound notification emails written against legacy URLs continue to resolve.

### Folio file layout

The Folio convention (`resources/views/pages/`) maps URL segments to file paths via `[param]` directories. The new layout:

```
pages/
├── triage.blade.php
├── activity.blade.php
├── projects/
│   └── [project]/
│       ├── index.blade.php              # project overview
│       ├── features/
│       │   ├── index.blade.php
│       │   └── [feature].blade.php
│       ├── stories/
│       │   ├── index.blade.php
│       │   └── [story]/
│       │       ├── index.blade.php       # story document (canonical)
│       │       └── subtasks/
│       │           └── [subtask]/
│       │               ├── index.blade.php  # drawer/page
│       │               └── runs/
│       │                   └── [run].blade.php  # run console
│       ├── runs/
│       │   └── index.blade.php
│       └── repos/
│           └── index.blade.php
```

The current `pages/stories/⚡show.blade.php` moves verbatim to `pages/projects/[project]/stories/[story]/index.blade.php`. The component logic does not change — only the URL by which it's reached.

### Slice plan

Migration ships in four slices behind individual PRs, each independently mergeable:

- **Slice 1** — sidebar shell + workspace renames. New sidebar layout, `/inbox` → `/triage`, `/events` → `/activity`, `users.active_project_id` migration, project switcher dropdown, no page-content changes. Existing 278 tests stay green.
- **Slice 2** — project-scoped listings. `/projects/{p}` overview + `/projects/{p}/{features,stories,runs,repos}`; legacy 301 redirects; breadcrumbs `{Project} › {Section}`. Cross-project firehose lists removed.
- **Slice 3** — nested Story / Subtask / Run console URLs. Story document moves to `/projects/{p}/stories/{s}`; subtask drawer and run console land. Notification emails / webhooks updated to canonical URLs.
- **Slice 4** — Feature page chrome. Rail/pill rolling up child story states; child story list with shared row chrome.

## Consequences

### Positive

- **Project context is visible everywhere** — the URL, the breadcrumb, and the sidebar all carry the active project. Switching project moves the whole UI atomically.
- **Triage role is separated from project role**. A reviewer working the cross-project queue and an engineer working a single project see distinct nav and distinct URLs.
- **Deep-links are self-describing** — `/projects/specify/stories/manage-context-items` orients the reader without opening the page.
- **Repos and Runs are scoped correctly** — they live under the project that owns them, not the workspace.
- **The Feature finally has its own document**, with the same rail/pill chrome the Story uses; rolling tallies make Feature-level state visible at a glance.
- **The brief's Slice 2 run-console URL contract can finally land** — the routes exist for the brief's nested run-console anatomy to attach to.

### Negative

- **Volume of file moves** — every existing page under `pages/` either moves under `pages/projects/[project]/...` or gets a redirect. Four slices and an explicit redirect map keep the blast radius bounded; tests cover every old → new mapping.
- **Active-project hydration is a new failure mode** — a stale session id pointing at a deleted project or a project the user no longer accesses must fall back gracefully. ADR specifies the fallback chain (column → first-accessible → picker); tests cover each branch.
- **Breadcrumbs become page chrome** — every page renders `{Project} › {Section} [› {Subsection}]`, so the breadcrumb component must be a first-class layout primitive rather than a per-page snippet.

### Neutral

- **ADR-0001 unchanged** — Story is still the only approval gate; the IA change is shell-level.
- **ADR-0006 unchanged** — multi-executor race mode is per-Subtask; the run console URL nests it under Subtask cleanly.
- **MCP tools unchanged** — the MCP tool surface (`get-story`, `list-runs`, etc.) returns IDs, not URLs; the client stitches URLs from the active context. The IA change ripples through the web shell only.

## Open questions

- **`/dashboard`** — kill outright (301 to `/projects/{p}`) or keep as a thin "all your projects" landing for users with multiple? Lean: kill; the project overview is the home; users who span projects use Triage as their cross-project surface.
- **Project picker on first login** — show only when the user has zero accessible projects, or also when they have many and no clear default? Lean: show only on zero; pick first accessible otherwise.
- **Breadcrumb for Triage / Activity** — render as just `Triage` / `Activity` (no chain), or as `Workspace › Triage`? Lean: just `Triage` / `Activity` — the workspace is ambient.
- **Triage filter chip "current project only"** — Slice 1 or Slice 2? Lean: Slice 2; Slice 1 ships cross-project only and adds the chip alongside the project-scoped routes.
- **MCP `current-context` tool** — should it return the active project, the workspace, or both? Lean: both; the agent already needs project context to scope MCP queries; the workspace is informational.
