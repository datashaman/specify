# Specify — Domain glossary

Living document. Terms are added as they're sharpened during design discussions. Implementation detail belongs in code or ADRs, not here.

## Core hierarchy

`Workspace → Project → Feature → Story → Task → Subtask`

- **Workspace** — tenant boundary, owned by a User. Hosts Projects, Repos, Teams, and ApprovalPolicies. In the UI, workspace is **ambient chrome** (top-left switcher), not a breadcrumb segment. Cross-workspace navigation is a route change; within a workspace, the breadcrumb starts at Project.
- **Project** — a body of work inside a Workspace. Holds Features and is linked M:N to workspace-scoped Repos via `project_repo` (with `role`, `is_primary`).
- **Feature** — product-owner framing of a capability. Holds Stories.
- **Story** — product-owner unit of value. Carries an integer `revision` (auto-bumps on edit), a description, notes, and AcceptanceCriteria. **The only approval gate** (ADR-0001).
- **AcceptanceCriterion** — observable behaviour the Story must satisfy. 1:1 with Task (ADR-0002). In the UI, AC and Task are not two separate lists: the Story's plan section is the AC list, with each row leading on the AC text (product-owner voice) and the Task description + Subtasks (engineering voice) folded beneath. The Plan toggle (Grooming / Run) drives default collapse — Grooming collapses everything below the AC; Run expands Task + Subtasks for live watching.
- **Task** — engineering contract for one AcceptanceCriterion. Owns 1+ Subtasks.
- **Subtask** — the unit an Executor runs (one Subtask per `ExecuteSubtaskJob`).

## Adjacent

- **Team** — workspace-scoped, M:N with User via `team_user`.
- **Repo** — workspace-scoped, attached to Projects via `project_repo`. Carries `provider`, encrypted `access_token`, and `webhook_secret`.
- **ApprovalPolicy** — cascade `workspace → project → story` with a `required_approvals` threshold. Resolved policy decides how many Approve decisions a Story needs. The threshold is surfaced in the UI as a tally pill in the Story breadcrumb (`Approved · 2/2`, `Pending · 1/2`, `Changes requested`). The Story right rail shows the immutable **decision log** (Approve / ChangesRequested / Reject / Revoke, approver, time) rather than a flat approver list. ChangesRequested resets the tally — the editor must surface this consequence before the click. Reject is terminal and lives in a "more" menu with a hard confirm. **Story authors cannot approve their own Stories** — the Approve button is hidden for the author. When threshold > 1, the rail lists eligible approvers.

## Runs and race mode

- **AgentRun** — one execution attempt. In single-driver mode, a Subtask has one AgentRun. In race mode (ADR-0006), a Subtask has N sibling AgentRuns — one per driver — each on its own branch and opening its own PR. The "winner" is the PR a human merges on the host VCS, not a flag in the app.
- **Run console** — scoped to one AgentRun. URL: `/stories/:story/subtasks/:subtask/runs/:agent_run_id`. Nested route preserves breadcrumb continuity.
- **Story revision** — integer on `Story.revision`, auto-bumps on any edit. Surfaces in the UI as `v7`. There is no snapshot table today; the run header simply shows the integer revision the run was dispatched against. Drift between current Story and run-time Story is *not* visualised in V1 — adding a `story_snapshots` table is a future ADR.
- **Subtask drawer** — opened from the Story plan or live band. Hosts the per-Subtask **leaderboard** (one row per sibling AgentRun). In single-driver mode the drawer shows one row; in race mode N rows. Click a row to enter that AgentRun's run console.
- **Live run band** — Story-level ambient strip. Grouped by Task (one block per Task), with a sub-bar over Subtasks inside each block. Mid-run plan growth (ADR-0005) only reflows the inside of the affected Task's block — the outer Story-level structure stays still. A racing Subtask's sub-segment is subdivided into N driver stripes. The band is *not* scoped to a single AgentRun. Per-Subtask state includes a distinct "review-response running" visual (comment-dot glyph, sky rail) for when a `respond_to_review` run is active on an already-merged-or-pending PR.

## Subtask outcomes

- **Already-complete** (ADR-0007) — a Succeeded-class outcome where the agent declares the spec already met and cites commit SHAs reachable from `HEAD`. Renders **prominently** in the spine and plan: green check + chain-link glyph, tooltip "Already complete · N commits cited", expanding to show the SHA list (each linkable to the host VCS) and the agent's `already_complete_reason`. Not muted — the declination is one of the most reviewer-relevant decisions the agent makes.
- **Clarifications** (ADR-0005) — structured signals from the executor (`kind`: ambiguity / conflict / missing-context / disagreement, plus message and optional `proposed`). Surfaced in three scopes:
  1. **Run console right ambient panel** — live cards as the run produces them, kind-chipped.
  2. **Subtask drawer** — clarifications strip above the leaderboard so a reviewer sees them before entering a specific AgentRun.
  3. **Story right rail** — aggregate `Clarifications (N)` link across all runs of the Story.
  A `!` badge marks Subtask segments in the live band when active clarifications exist — an orthogonal ambient signal, distinct from running/failed state.

## Plan growth and provenance

- Mid-run append-only growth (ADR-0005) is bounded at +3 Subtasks per run, scoped to the parent Task.
- **Subtask drawer / run console spine** renders original Subtasks as fixed segments; appended Subtasks land in an "Appended this run (+N of 3 cap)" tray beneath the spine — never reflowing the original list.
- Every Subtask row in the spine, plan, and drawer shows a small `+` glyph when `subtasks.proposed_by_run_id` is set, with a tooltip naming the originating AgentRun.

## Run console anatomy by driver

The Logs tab has two distinct anatomies, selected by `agent_runs.executor_driver`:

- **Structured-output drivers** (e.g. `LaravelAiExecutor`) — foldable per-tool-call blocks, kind filter chips (tool / edit / shell / thinking / errors), dimmed thinking lines. Timeline tab (flame chart) is available.
- **CLI drivers** (`CliExecutor`) — raw ANSI stdout + stderr in a terminal-style scrollback, ANSI colour rendering, no kind filters (full-text search instead). Sentinel blocks (`<<<SPECIFY:...>>>` — see ADR-0007 for `already_complete`; future: `clarifications`) are detected and rendered **inline as structured callouts** within the stdout stream. Timeline tab is **hidden**, not placeheld.

The Logs tab header carries a small driver badge so watchers know which anatomy they're seeing. In race mode, sibling AgentRuns on the same Subtask may have different anatomies — the drawer's leaderboard is uniform; clicking into each opens its driver-appropriate console.

**Open follow-up:** some CLIs (e.g. Claude Code's `--output-format stream-json`) emit structured events that could promote a CLI run to the structured anatomy. Out of scope for V1; revisit when the CLI sentinel parser is generalised.

## Cancel and retry

Neither cancel nor retry is in V1.

- **Cancel** — `Executor::execute` returns one `ExecutionResult` at the end and has no in-process kill switch; `LaravelAiExecutor` runs synchronously inside `ExecuteSubtaskJob`; `CliExecutor` has a configurable timeout but no cooperative cancel plumbing. Race-mode cancellation is also semantically ambiguous (one sibling vs. all). The button is **hidden** until a cancel ADR lands; it's not stubbed.
- **Retry** — manual Subtask retry has no domain affordance today. Failed Subtasks surface their failure (state + error) but offer no one-click retry. Re-running is an ADR-worthy capability, not a UI feature.

## Realtime transport

Reverb is reserved for low-frequency state events where push beats poll meaningfully. Anything per-line or per-tool-call goes via HTTP poll.

| Channel / transport | Slice | Frequency | Purpose |
|---|---|---|---|
| `stories.{id}` (Reverb) | 1 | on state change | approval transitions, run lifecycle milestones, revision bumps |
| `runs.{agent_run_id}` (Reverb) | 2 | on subtask transition | current subtask index, state, elapsed |
| `runs/{id}/logs?after={cursor}` (HTTP poll, 1–2s) | 2 | poll | log entries; driver-shaped (structured blocks for laravel-ai, ANSI scrollback for cli) |
| `runs/{id}/diff` (HTTP, on tab focus) | 2 | one-shot | cumulative working-tree diff |
| `runs.{agent_run_id}.review` (Reverb) | future | on webhook delivery | review-response dispatch |
| presence / multiplayer cursors | (dropped) | — | not building Figma; "X is viewing" is not a feature |

Reverb log streaming is deferred to a future slice with its own ADR — `Executor::execute` returns one `ExecutionResult` at the end (no streaming hook), and `CliExecutor`'s process model has no line-buffered broadcast plumbing today. Per-tool-call / per-line streaming requires executor instrumentation that isn't a UI design decision.

## UI stack and existing surface

- **Stack**: Livewire Volt single-file components mounted as Laravel Folio pages (file convention `⚡name.blade.php`). Classed Livewire components are not the working pattern (`app/Livewire/` holds only Actions). New pages and shells in the brief should follow Volt-in-Folio, not Livewire classes.
- **Triage**, not Inbox. The page that holds Stories awaiting approval is named **Triage** (Linear's term — implies a queue you process). Existing `pages/⚡inbox.blade.php` is renamed.
- **Story document** is extended in place (`pages/stories/⚡show.blade.php`) — it already wires `AcceptanceCriterion`, `Task`, `Subtask`, `AgentRun`, `ApprovalService`, `ExecutionService`. The redesign is layout + visual system on top of existing data wiring, not a rewrite.

## AgentRun kinds

- `execute` — the original Subtask run that produces a diff and opens a PR (ADR-0004). Has a subtask spine in its run console.
- `respond_to_review` — dispatched by webhook when review comments arrive on a PR Specify owns (ADR-0008). Pushes commits to the existing PR's branch; does **not** open a new PR, change cascade, or reset Story approval. Capped by `max_review_response_cycles` per PR.

Both kinds share the same run-console shell, parameterised by kind:
- `execute`: spine + tabs (Logs / Timeline / Diff / PR).
- `respond_to_review`: no spine; tabs become Logs / Timeline / Diff / **Comments being addressed** (file/line groups). Header shows cycle counter (`2 of 3`) and a link back to the parent `execute` run.

The **Subtask drawer** renders all AgentRuns for a Subtask as a tree: top-level rows are `execute` runs (1 in single-driver mode, N in race), each expandable to show its `respond_to_review` children chronologically with a per-PR cycle indicator.
