# Specify — UI/UX Design Brief

**Status:** Draft for review (rewritten 2026-05-02 after `/grill-with-docs` against ADRs 0001–0008)
**Audience:** Implementer (senior eng, comfortable with Livewire Volt / Folio / Flux UI / Tailwind 4 / Reverb)
**Scope:** End-to-end interface for a hybrid spec + execution system. `Workspace -> Project -> Feature -> Story -> AcceptanceCriterion / Scenario -> Plan -> Task -> Subtask`, with autonomous executor runs, PR review-response runs, and race mode.

This brief is opinionated. Decisions taken during the grill are recorded in `CONTEXT.md` at the repo root — read that for the canonical glossary; this document is the implementation-shaped expansion.

---

## 1. Principles

1. **Approval is the loudest signal.** Story approval gates the product contract and Plan approval gates execution (ADR-0001). Their visual states must be unmistakable from any list, document, or run view — not one chip among many.
2. **Differentiate levels visually.** Same chrome at every level is Jira-fatigue. Project/Feature feel architectural; Story feels like a contract document; Task/Subtask feel like execution rows.
3. **One live band, then hard navigate.** Stories show a single ambient band when work is in flight. The deep-dive console is its own page. Nothing in between.
4. **Don't lie about capabilities the system doesn't have.** No fake SHAs (Story revision is an integer). No snapshot links (no snapshot table). No retry buttons (no retry job). No cancel buttons (no cooperative cancel). When the schema doesn't support it, the UI doesn't pretend it does.
5. **Motion budget: one moving thing per pane.** Logs may scroll; leaderboards may not also reorder. Quiet enough to leave on a second monitor.
6. **Never spinner-as-status.** Always render: current subtask, current tool call, elapsed time. Opacity is the agent-UX antipattern.
7. **Reverb is for low-frequency state events; HTTP-poll for everything else.** Push beats poll only when the event is rare. Per-line streaming is HTTP-poll until executor instrumentation is its own ADR.

---

## 2. Information architecture

This brief was drafted before the IA was finalised; the routes that
shipped match ADR-0012 — see that ADR for the load-bearing reference.
Summary of the canonical routes:

```
/triage                                            Cross-project approval queue (renamed from /inbox)
/activity                                          Cross-project event stream (renamed from /events)
/projects                                          Projects index
/projects/:project                                 Project landing (currently the features list; real Overview deferred)
/projects/:project/features/:feature               Feature document
/projects/:project/stories                         Stories index for the project
/projects/:project/stories/:story                  Story document — the centerpiece
/projects/:project/stories/:story/subtasks/:subtask                 Subtask drawer / page
/projects/:project/stories/:story/subtasks/:subtask/runs/:run       Run console
/projects/:project/runs                            Runs for the project
/projects/:project/repos                           Repos for the project
```

Legacy URLs (`/inbox`, `/events`, `/stories/:id`, `/runs/:id`, flat
`/stories`, `/runs`, `/repos`, `/features`) all 301-redirect to the
canonical project-scoped equivalents — see `routes/web.php`.

**Workspace is ambient chrome, not a breadcrumb segment.** A switcher sits in the top-left of the global nav (Linear-style); cross-workspace navigation is a hard route change. Inside a workspace, the breadcrumb starts at Project: `Project › Feature › Story › Subtask › Run`.

Slide-overs are used **within** a level — opening a Story from a Feature, or opening a Subtask drawer from a Story plan. Cross-level navigation is a real route change.

---

## 3. Visual system

### 3.1 Approval state — the color rail

A 3-px left border rail carries Story approval state on every Story-bearing surface (Triage rows, Feature lists, Story document, Run console header). Approval state is **never** a chip among other chips.

| State              | Rail                                 | Pill text                  |
|--------------------|--------------------------------------|----------------------------|
| Draft              | `slate-300`                          | Draft                      |
| Pending approval   | `amber-500`                          | Pending · `n/N`            |
| Approved           | `emerald-500`                        | Approved · `N/N`           |
| Changes requested  | `rose-500`                           | Changes requested          |
| Rejected           | `slate-500`                          | Rejected                   |
| Running            | `sky-500` (animated 1.5s pulse)      | (run-state pill, see 3.2)  |
| Run complete       | `emerald-600`                        | Run complete · PR #N       |
| Run failed         | `rose-600`                           | Run failed                 |

The approval pill always carries the **threshold tally** (`Pending · 1/2`, `Approved · 2/2`) so a single approver knows whether their click is the last word.

### 3.2 Subtask state vocabulary

State is shown as a dot in the spine, plan, and drawer.

| State                    | Glyph         | Meaning                                                                                       |
|--------------------------|---------------|------------------------------------------------------------------------------------------------|
| Queued                   | `○`           | dispatched, not yet started                                                                   |
| Running                  | `◐` pulse     | active                                                                                        |
| Done                     | `✓`           | succeeded with a diff and PR                                                                  |
| Already complete         | `✓⛓`          | declared `alreadyComplete` with cited SHAs (ADR-0007). Clicking expands the SHA list + reason. |
| Failed                   | `✗`           | terminal failure                                                                              |
| Blocked                  | `⊘`           | waiting on something (race siblings, etc.)                                                    |
| Review-response running  | `◐💬`         | a `respond_to_review` run is active on this Subtask's PR                                      |

**Provenance glyph**: a small `+` appears next to any Subtask row whose `proposed_by_run_id` is non-null (ADR-0005), with a tooltip naming the originating AgentRun. Visible everywhere a Subtask row renders.

**Clarifications badge**: a `!` badge appears on the Subtask segment in the live band when active clarifications exist (ADR-0005). Orthogonal to running/failed state.

### 3.3 Level differentiation

| Level    | Surface                          | Chrome cues                                                            |
|----------|----------------------------------|------------------------------------------------------------------------|
| Workspace | Top-left switcher (ambient)     | Org icon + name; never in breadcrumb                                   |
| Project  | Card grid / landing              | Large heading, hero blurb, repo chips                                  |
| Feature  | Document landing                 | Prose-led, stories appear as a structured list                         |
| Story    | Document with right rail         | Editor-grade typography, color rail, decision log on right             |
| Task     | Folded under AC inside Story plan | Compact, secondary; expanded only in Run-mode toggle                  |
| Subtask  | Row inside Task or run console spine | Terse, monospace identifier, state dot, provenance glyph if appended |

### 3.4 Plan view modes

The Story plan section has a **Plan toggle** (Notion-style):
- **Grooming** (default when no run is active): Tasks collapsed under their AC. AC text leads.
- **Run** (default during/after a run): Tasks expanded, Subtasks visible inline with state dots.

Per-user sticky.

---

## 4. Page-by-page

### 4.1 Workspace chrome (global)

- Top-left **workspace switcher** with org icon + name. Click opens a popover listing the user's workspaces. Switching is a hard route change.
- A workspace-scoped left nav sits below: Triage / Projects / Runs / Repos / Events.
- Workspace landing (`/`) is the post-switch home: card grid of Projects + recent activity strip + workspace member count + active approval policy summary. Replaces the previous `⚡dashboard.blade.php`.

### 4.2 Triage (`/triage`)

Renamed from `/inbox`. Stories awaiting approval, color-railed.

**Row anatomy** (left → right):
- Color rail (amber for Pending, rose if Changes requested)
- Story title (bold) + Feature name (muted)
- Plan summary: `5 ACs · 13 subtasks` + delta from prior revision when re-submitted
- Author avatar + relative time
- Threshold tally on the right (`1/2`, `0/1`)
- Action buttons:
  - **Approve** — hidden if the viewer is the Story author (authors cannot approve their own Stories)
  - **Request changes** — always visible
  - **Reject** lives in a "more" menu with a hard confirm — terminal, distinct from Request changes
- Keyboard: `A` Approve, `C` Request changes, `R` Reject (with confirm)

**Empty state**: "No stories pending approval. Nothing's blocking the agents." No illustration.

**Filters at top**: project chip, author chip, age. No saved views in V1.

### 4.3 Projects index (`/projects`)

Existing `pages/projects/⚡index.blade.php`, extended. Card per project; each card shows story counts by state (color-rail summary), open-runs count, primary repo chip.

### 4.4 Project landing (`/projects/:project`)

Existing `pages/projects/⚡show.blade.php`, extended.

- Hero: project name, blurb, primary repo (set via `set-primary-repo`), executor config summary (`single-driver: claude-code` or `race: [claude-code, codex, gemini]`).
- **Features grid**: cards. Each card = feature title + story counts by state + open-runs count.
- **Live activity strip**: last N events (run started, story approved, PR opened, review-response dispatched). Truncates; "View all" → `/events`.

### 4.5 Feature document (`/features/:feature`)

Existing `pages/features/⚡show.blade.php`, extended.

- Document layout (max-width prose). Title, owner, description.
- **Stories list** as cards in vertical stack — color-railed, one-line title + plan summary + state pill (with tally).
- New Story button (top-right). Clicking a Story opens a **slide-over** (preserves Feature context); ⌘-click → hard route to Story document.

### 4.6 Story document (`/stories/:story`) — the centerpiece

Extended in place at `pages/stories/⚡show.blade.php` (already wires `AcceptanceCriterion`, `Task`, `Subtask`, `AgentRun`, `ApprovalService`, `ExecutionService`). Layout and visual changes only — data wiring stays.

#### Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│ ← Feature › Story  [Approved · 2/2]  v7  ⌥E to edit  ⌘K palette    │ breadcrumb + actions
├──────────────────────────────────────────────────────────────────────┤
│ ▌ Story title                                                        │
│ ▌ As a {role}, I want {thing}, so that {outcome}.                    │
│ ▌                                                                    │
│ ▌ ── Plan  [Grooming | Run]  · 5 ACs · 13 subtasks · v7              │
│ ▌ ▸ AC1 — User can submit a story via the MCP tool                   │
│ ▌ ▸ AC2 — Submitting persists an ApprovalEvent                       │
│ ▌ ▾ AC3 — Wire MCP submit-story tool                                 │
│ ▌    Task: Implement submit-story handler             [running 2/4]  │
│ ▌    ✓ Subtask 3.1                                                   │
│ ▌    ◐ Subtask 3.2 (current)                                         │
│ ▌    ○ Subtask 3.3                                                   │
│ ▌    ○ Subtask 3.4                                                   │
│ ▌    + Subtask 3.5  (appended by Run #41)                            │
│ ▌ ▸ AC4 — ...                                                        │
│ ▌                                                                    │
│ ▌ ── Live run band (Slice 2; only when work is in flight) ─────      │
│ ▌ Task1[▮▮▮▮] Task2[▮▮◐▯] Task3[▯▯▯] Task4[▯▯] Task5[▯▯▯]            │
│ ▌ Run #41 · subtask 3.2 · claude-code · 6m12s  → Watch               │
└──────────────────────────────────────────────────────────────────────┘
                              right rail (collapsible) →
                              ┌──────────────────────────┐
                              │ State                    │
                              │ ▌ Approved · 2/2 · v7    │
                              │                          │
                              │ Decision log             │
                              │   ✓ alice  approved      │
                              │     2026-05-01 14:02     │
                              │   ✓ bob    approved      │
                              │     2026-05-01 14:09     │
                              │                          │
                              │ Eligible approvers       │
                              │   alice, bob, carol      │
                              │                          │
                              │ Linked PRs               │
                              │   #123 · open            │
                              │   #119 · merged          │
                              │                          │
                              │ Recent runs              │
                              │   #41 v7 · running       │
                              │   #38 v6 · failed        │
                              │   #34 v5 · complete      │
                              │                          │
                              │ Clarifications (4) →     │
                              └──────────────────────────┘
```

#### Plan section (AC-led, Task-folded)

- The plan section *is* the AC list. Each row leads with the AC text (product-owner voice, prominent).
- Below each AC — collapsed by default in **Grooming**, expanded by default in **Run** — sits the Task description (engineering voice, secondary) and the Subtask list with state dots.
- Subtask rows show the provenance `+` glyph when appended mid-run; clicking opens the Subtask drawer.
- Reordering is on the AC row; Task and Subtasks move with it. Subtasks within a Task can be reordered independently.

#### Editing

- Inline edit on click for AC text and Story body. No modal.
- **Plan editing** (adding/editing/removing ACs, Tasks, or Subtasks) opens an in-page editor with a persistent banner:
  > **Saving will reopen current Plan approval.** Story: Approved · 2/2 · v7. Plan: Approved · 2/2 · p3.
  >
  > Plan delta: +2 subtasks, ~1 edited, −0 removed. [View diff]
- **Product contract editing** (Story body, AC text, scenarios) uses the same banner shape but says:
  > **Saving will reopen Story approval and current Plan approval.** Story: Approved · 2/2 · v7. Plan: Approved · 2/2 · p3.
- Save button label flips to **"Save & request re-approval"** when the change reopens approval. Cancel discards.
- A `respond_to_review` run does *not* count as the kind of edit that reopens approval (ADR-0008).
- While a run is executing the previous revision, edits queue as the next revision: banner says "Run #41 still executing against v7. Saving creates v8 and queues for re-approval."

#### Decision log right rail

- **Immutable** chronological list of `StoryApproval` rows: Approve / ChangesRequested / Reject / Revoke, with approver and time.
- A user can **revoke** their own prior decision via a small `…` menu on their row. Revoking when threshold was met rolls state back to Pending.
- ChangesRequested resets the tally; the editor's persistent banner makes that consequence visible *before* the click.

#### Live run band (Slice 2)

When any AgentRun for the Story is non-terminal:
- Single horizontal band at the bottom of the document, full-width.
- **Grouped by Task** — one block per Task — with a sub-bar over Subtasks inside each block. Mid-run plan growth (ADR-0005) only reflows inside one Task block; the outer structure stays still.
- Racing Subtasks have their sub-segment subdivided into N driver stripes.
- A `!` badge marks Subtasks with active clarifications.
- A comment-dot glyph + sky rail marks Subtasks with an active `respond_to_review` run on their PR.
- "Watch" button hard-navigates to the Subtask drawer (or, when only one Subtask is in flight, directly to its run console).

### 4.7 Subtask drawer (`/stories/:story/subtasks/:subtask` slide-over)

The home for the **leaderboard**. Opens as a slide-over from the Story plan (preserving Story context).

#### Anatomy

- Header: AC text (parent), Task description, Subtask name, state dot.
- **Clarifications strip** (when present) — kind-chipped cards above the leaderboard so reviewers see them before drilling into a specific run.
- **Leaderboard** — a tree of AgentRuns:
  - Top-level rows are `execute` runs (1 in single-driver mode, N in race). Each row shows: driver, started, duration, current step, branch, PR link with merge state, run-state badge.
  - Each row expands to show its `respond_to_review` children chronologically, with a per-PR cycle counter (`2 of 3 used`).
- Click a row → enter that AgentRun's run console (nested route).
- Hard-navigate to full-page if drawer feels cramped (race with N>3 siblings).

### 4.8 Run console (`/stories/:story/subtasks/:subtask/runs/:agent_run_id`)

Scoped to **one** AgentRun. Same shell for both `kind`s, parameterised.

#### Header

```
┌─────────────────────────────────────────────────────────────────────────┐
│ ← Subtask  [Run #41 · v7 · running · 6m 12s]                           │
│ kind: execute · driver: claude-code · branch: specify/{feature-slug}/{story-slug} │
│ Started 12:04 · subtask step 2/4                                        │
└─────────────────────────────────────────────────────────────────────────┘
```

For `respond_to_review`:

```
┌─────────────────────────────────────────────────────────────────────────┐
│ ← Subtask  [Run #43 · responding to PR #123 · cycle 2 of 3]             │
│ kind: respond_to_review · driver: claude-code · branch: specify/...     │
│ Parent run: #41 → │
└─────────────────────────────────────────────────────────────────────────┘
```

No "Cancel" button in V1 (no cooperative cancel exists in the executors today — ADR-worthy follow-up).

#### Left spine (`execute` only)

Vertical list of Subtask **steps** (the current Subtask's plan position is fixed; this spine shows step progression within the run).

Wait — clarify: the "spine" in the `execute` console is **the parent Task's Subtasks** (the run is for one Subtask, but the agent's progress through that Subtask isn't itself segmented). Reconsider: the spine is the run's progression within its single Subtask, expressed as **completed phases** (prepare workdir → checkout → execute → commit → diff → push → open PR — the SubtaskRunPipeline phases). State dots track which phase is active.

**Appended-this-run tray** sits beneath the spine when the executor has emitted `proposed_subtasks` (ADR-0005): "+N of 3 cap" header, list of appended subtask names. Original phases above never reflow.

For `respond_to_review`: **no spine** — there's no plan being walked. The space is occupied by the **Comments-being-addressed** pane (file/line groups, status: addressing / done).

#### Center — tabs

| Tab           | `execute`                                         | `respond_to_review`                                  |
|---------------|---------------------------------------------------|------------------------------------------------------|
| Logs          | Driver-shaped (see 4.9)                           | Driver-shaped                                        |
| Timeline      | Flame-chart (laravel-ai only; **hidden** for cli) | Same rule                                            |
| Diff          | Cumulative working-tree diff (HTTP, on tab focus) | Same                                                 |
| PR            | PR header + body + advisory review feedback       | (no separate tab — addressed via Comments pane)      |
| Comments      | (n/a)                                             | File/line groups of review comments being addressed  |

**PR tab error states**:
- Run completed but `pull_request_error` is set: shows the error, the pushed branch name (`specify/{feature}/{story}` or race suffix), and a host-VCS "compare branches" link so the operator can open the PR manually. **No retry button** in V1; ADR-0004's follow-up retry tool is a future ADR.

#### Right ambient (collapsible)

Always-on summary panel.
- **Currently doing**: one-line "Editing src/Services/PlanWriter.php" — updates inline, no log scroll required.
- **Last tool call**: header only (structured drivers); last stdout line (CLI drivers).
- **Live clarifications cards**: kind-chipped, one card per clarification, as the run produces them.

### 4.9 Logs anatomy by driver (Slice 2)

Two distinct anatomies, picked by `agent_runs.executor_driver`. Driver badge in the Logs tab header.

#### Structured-output drivers (`LaravelAiExecutor`, `FakeExecutor`)

- Foldable per-tool-call blocks: header always visible (`Edit src/foo.ts +12 −3`); body collapsible (args, result).
- Filter chips: tool / edit / shell / thinking / errors. Multi-select.
- Thinking renders dimmer (`text-slate-500 italic`).
- Autoscroll-with-pause; "Jump to live ▼ (N new)" pill on scroll-up.
- ⌘F in-pane search.

#### CLI drivers (`CliExecutor`)

- Raw ANSI stdout + stderr in terminal-style scrollback. ANSI colour rendering.
- No kind filters — full-text search instead.
- **Sentinel blocks** (`<<<SPECIFY:already_complete>>>...<<<END>>>` per ADR-0007; future: clarifications) are detected and rendered **inline as structured callouts** within the stdout stream.
- Same autoscroll-with-pause behaviour.

**Open follow-up** (not in V1): some CLIs (e.g. Claude Code's `--output-format stream-json`) emit structured events that could promote a CLI run to the structured anatomy. Out of scope; revisit when the CLI sentinel parser is generalised.

#### Transport

- HTTP polling on `runs/{id}/logs?after={cursor}` every 1–2s in V1. No Reverb log streaming.
- Reverb log streaming is deferred to a future slice with its own ADR — `Executor::execute` returns one `ExecutionResult` at the end and has no streaming hook; `CliExecutor`'s process model has no line-buffered broadcast plumbing today.

### 4.10 `respond_to_review` run console

Same shell as `execute`, parameterised:
- Header carries cycle counter and parent-run link.
- No left spine; **Comments being addressed** pane in its place — file/line groups, each comment with status (addressing / done / clarification-only).
- Tabs: Logs / (Timeline if structured driver) / Diff / Comments.
- **No PR opens** — commits push to the existing PR's branch.

A `respond_to_review` run does not change cascade, does not reset approval, does not produce new Subtasks (ADR-0008).

---

## 5. Reverb channels and HTTP transport

| Channel / endpoint | Slice | Frequency | Purpose |
|---|---|---|---|
| `stories.{id}` (Reverb) | 1 | on state change | approval transitions, run lifecycle milestones, revision bumps |
| `runs.{agent_run_id}` (Reverb) | 2 | on subtask transition / phase change | current step, state, elapsed |
| `GET runs/{id}/logs?after={cursor}` (HTTP poll) | 2 | 1–2s | log entries; driver-shaped |
| `GET runs/{id}/diff` (HTTP) | 2 | one-shot, on tab focus | cumulative working-tree diff |
| `runs.{agent_run_id}.review` (Reverb) | 3 | on webhook delivery | review-response dispatch |
| presence / cursors | (dropped) | — | not building Figma |

Each Reverb event payload follows `{type, ts, data}`.

---

## 6. Component / page inventory

Stack: **Livewire Volt SFCs in Folio pages** (file convention `⚡name.blade.php`). Classed Livewire components are not the working pattern (`app/Livewire/` holds only Actions). The brief uses Volt SFCs for composition; Flux UI primitives for chrome; Tailwind 4 for layout.

### 6.1 Primitives (Flux UI + Tailwind components)

| Component | Purpose |
|---|---|
| `<x-rail>` | Left color rail (props: `state`). Story rows, document, run header. |
| `<x-state-pill>` | Text pill with threshold tally where applicable. |
| `<x-state-dot>` | `○ ◐ ✓ ✓⛓ ✗ ⊘ ◐💬` with motion respect for `prefers-reduced-motion`. |
| `<x-task-band>` | One block per Task with a sub-progress bar over Subtasks. Used in the live run band. |
| `<x-driver-stripe>` | Subdivision of a Subtask sub-segment into N driver stripes for race mode. |
| `<x-tool-call-block>` | Foldable header/body block (structured drivers). |
| `<x-stdout-pane>` | ANSI-rendered scrollback with sentinel-callout detection (CLI drivers). |
| `<x-clarification-card>` | Kind-chipped card for a clarification. |
| `<x-decision-row>` | One immutable row in the decision log. |
| `<x-provenance-glyph>` | `+` glyph + tooltip for appended Subtasks. |

### 6.2 Volt SFC pages (Folio)

Existing — extended in place:
- `pages/⚡dashboard.blade.php` → becomes the workspace landing (route `/`).
- `pages/⚡inbox.blade.php` → **renamed** to `pages/⚡triage.blade.php`.
- `pages/projects/⚡index.blade.php`, `⚡show.blade.php` — extended with workspace chrome and cards.
- `pages/features/⚡show.blade.php` — slide-over story navigation.
- `pages/stories/⚡show.blade.php` — **layout + visual rewrite**, data wiring retained. AC-led plan, decision log rail, live run band (Slice 2).
- `pages/runs/⚡index.blade.php` — already renders `pull_request_error`; aligned to new state vocabulary.
- `pages/repos/⚡index.blade.php` — moved under workspace routing.
- `pages/events/⚡index.blade.php` — feeds the Project landing's live activity strip.

New in this brief:
- `pages/stories/subtasks/⚡show.blade.php` — Subtask drawer / page with leaderboard.
- `pages/stories/subtasks/runs/⚡show.blade.php` — Run console (parameterised by `kind`).

### 6.3 Volt fragments / partials

- `live-run-band.blade.php` — listens on `stories.{id}`, renders Task blocks; only mounts when a non-terminal run exists.
- `run-console-shell.blade.php` — shared shell for both `kind`s; switches spine vs Comments pane on `kind`.
- `logs-pane.{structured,cli}.blade.php` — driver-shaped variants.

---

## 7. Interactions & keyboard

- **⌘K** global command palette (Linear shape).
- **⌥E** edit current document (Story body or plan).
- **A / C / R** in Triage row → Approve / Request changes / Reject (R confirms first).
- **J / K** down/up in any list.
- **G then T** → triage. **G then R** → all runs. **G then P** → projects.
- **⌘F** in-pane search in logs.

All keybindings registered through the Flux command surface; no custom global key handler.

---

## 8. Empty / loading / error states

- **Loading**: skeletons matching populated structure (no centered spinners).
- **No runs yet**: Story document just has no live band. No nag.
- **Run failed**: rail goes rose; failed phase shown in spine with error block in Logs. **No retry button in V1.**
- **PR creation failed (ADR-0004)**: Run rail stays emerald (run succeeded). PR tab shows the error + pushed branch name + host-VCS compare-branches link. **No retry button in V1**; manual PR open on the host VCS.
- **`respond_to_review` cycle cap reached**: Subtask drawer shows "Cycle cap reached (3/3)" on the parent `execute` row; the leaderboard hides the Add-cycle affordance (there isn't one — webhooks dispatch automatically until the cap).
- **Reverb disconnected**: amber banner "Live updates paused — reconnecting…". Components fall back to polling for state-only data.

---

## 9. Accessibility

- All color-rail semantics duplicated in text (state pill is the readable label).
- Timeline flame chart has a tabular fallback (toggle in tab header) — only present on structured-driver runs anyway.
- All keybindings discoverable via `?` overlay.
- Live regions (`aria-live="polite"`) for run state changes.
- Motion respects `prefers-reduced-motion`: pulse becomes static; segmented bar transitions instant.

---

## 10. Slice plan

Three independently-shippable slices.

### Slice 1 — Spec surface (no realtime run viz)

- Workspace switcher chrome + workspace landing.
- Triage page (renamed from inbox).
- AC-led Story document with Plan toggle (Grooming / Run).
- Decision-log right rail with Approve / Request changes / Reject / Revoke. Author cannot approve own Story.
- Plan-edit reset-approval banner with delta preview.
- Threshold tally pill (`Pending · 1/2`).
- `stories.{id}` Reverb channel — state events only (approval transitions, run lifecycle milestones, revision bumps).
- Existing Folio pages reconciled: `⚡inbox` → `⚡triage`, projects/features/stories/runs/repos pages extended with workspace chrome.

**Not in slice**: live run band, Subtask drawer, Run console, race UI, Timeline, log streaming.

### Slice 2 — Run console & Subtask drawer

- Subtask drawer with leaderboard (1 row single-driver, N rows race; `respond_to_review` children expandable).
- Nested Run console at `/stories/:story/subtasks/:subtask/runs/:agent_run_id`.
- Driver-aware Logs anatomy (structured blocks for laravel-ai; ANSI scrollback + inline sentinel callouts for cli).
- Diff & PR tabs (HTTP, on tab focus).
- `runs.{agent_run_id}` Reverb channel for state.
- HTTP-polled logs at `runs/{id}/logs?after={cursor}` (1–2s).
- Live run band on Story (Task-grouped, sub-bar by Subtask, race driver stripes, clarification `!` badge).
- Already-complete (`✓⛓`) Subtask state with cited SHA expansion.
- Clarifications surfaces: run-console ambient panel, Subtask drawer strip, Story right rail digest.
- Provenance `+` glyph for appended Subtasks; appended-this-run tray in the run console.

**Not in slice**: `respond_to_review` console, Timeline tab, race "diff-of-diffs" tooling.

### Slice 3 — Review-response console + cycle UX + Timeline

- `respond_to_review` console variant (no spine; Comments-being-addressed pane).
- `runs.{agent_run_id}.review` channel for review-response dispatch.
- Review-response state on the live band (comment-dot glyph).
- Timeline tab (flame chart) for structured-driver runs; hidden on CLI runs.

### Out of V1 entirely (each its own ADR)

- Retry-PR button (ADR-0004 follow-up).
- Run cancel / Subtask retry.
- `story_snapshots` table + drift indicator + snapshot link.
- Reverb log streaming + executor instrumentation.
- Multiplayer presence / cursors.
- Race "diff-of-diffs" comparison tooling.
- Stream-JSON CLI promotion to structured anatomy.

---

## 11. Open questions

1. **PR review feedback location** — single right-rail thread, or PR tab section, or both? Brief assumes **both** (mid-run shows in run-console ambient; full thread on PR tab). Validate after Slice 2.
2. **Plan diff preview format** — git-style hunks vs. structured "added/edited/removed" cards. Brief assumes structured cards; revisit once we see real plan diffs.
3. **Race-mode resource cost** — running 3 executors per Subtask is expensive. Per-Story toggle? Likely yes; out of scope for this brief but flagged for ADR.
4. **Workspace landing vs Projects index** — workspace landing (`/`) and `/projects` overlap. Brief makes `/` show projects + recent activity + members, and `/projects` a denser project-only grid. Could collapse to one if the projects grid is the only useful surface.
5. **Mobile** — out of scope. Story document and Triage are read-only mobile-ok; Run console is desktop-only.

---

## 12. Decisions deferred to implementation

- Specific Tailwind tokens for rail colors (current draft uses default palette; harmonize with brand later).
- Whether Plan toggle persists per-user or per-Story-state (likely per-user, sticky).
- Pagination strategy for `/runs` and `/events`.
- Whether sentinel callouts in CLI logs link out to the structured representation when available (e.g. `already_complete` SHAs as clickable host-VCS links — likely yes, low-cost).
