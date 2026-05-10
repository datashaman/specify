# 0015. Project- and Story-scoped context assets

Date: 2026-05-10
Status: Accepted

## Context

Until this ADR, Specify had no first-class place for the briefing material a
product owner brings to a Story: reference images, walkthrough captures,
PDFs, links to Figma / Stitch / Sketch, free-form notes. Two earlier
attempts (#1, #44) shipped a Project-only schema but were closed unmerged.

The AI agents that power plan generation and Subtask execution worked off a
4 KB recency-and-mentions brief (`RecencyContextBuilder`). They had no way
to cite product-owner context the human had explicitly chosen to surface.

We needed a place to store assets, a per-Story selection mechanism, and an
injection point into the AI flow — without breaking the approval invariants
that gate Story / Plan changes.

## Decision

Adopt a `ContextItem` aggregate with two scopes and an explicit per-Story
selection.

### Storage and scope

- **Dual scope.** A `ContextItem` belongs to a `Project` (shared) or a
  single `Story` (scoped). `project_id` is always set; `story_id` is set
  only for story-scoped items.
- **Selection per Story.** A pivot (`context_item_story`) records which
  items a Story has selected. The picker shows
  `Story::availableContextItems()` — the union of project-scoped items in
  the same Project plus this Story's own story-scoped items. Story-scoped
  items are auto-included on creation and cannot be toggled off.
- **Types.** `file`, `link`, `text`. No video in v1.

### Approval-reopen invariants

- **Story-scoped CRUD reopens Story approval.** Creating, updating, or
  deleting a story-scoped `ContextItem` calls
  `StoryRevisionLifecycle::recordContentArtifactChanged($story)` — the same
  helper `AcceptanceCriterion` and `Scenario` writers use. One call per
  write.
- **Project-scoped CRUD does not reopen any Story.** Even if a project
  asset is currently included by N Stories, editing it leaves their
  approvals intact. This is the v1 trade-off — re-fanning out approval
  storms across many Stories on every project asset edit is worse than
  letting the included copies drift.
- **Toggling inclusion reopens Story approval.** `ContextItemSelector` is
  the only legal mutator for the pivot. `setIncluded()` and `bulkSet()`
  each call `recordContentArtifactChanged` at most once per public-method
  invocation, only when the included set actually changed. The picker uses
  `bulkSet` to avoid per-checkbox approval storms.

### Storage on a single `private` disk

A new `private` disk in `config/filesystems.php` backs all uploads. In
local dev it points at `storage/app/private`; in hosted runtime the same
disk name is repointed at S3 with no code change.

Files are stored under `context/{ulid}/{slugified-name}`. On
`ContextItem` delete the underlying file is hard-deleted at the disk; the
DB row is soft-deleted for audit. Document what hard-delete means before
this is migrated to S3 (versioning rules differ).

### Lazy, bounded summarisation

Long bodies are compressed by `ContextSummariser` (laravel/ai) via
`ContextCompressor`, dispatched as `SummariseContextItemJob`:

- The job uses the **creator's** BYOK credential. Missing creds are not a
  failure — they collapse to `summary_status='skipped'`. `bodyForContext()`
  then falls back to a truncated raw body so plan generation still works.
- Short text (under `specify.context.assets.summary_threshold_chars`,
  default 2000) is marked `Skipped` at create time — no point summarising.
- Summarisation runs eagerly on upload / create / body-change. There is no
  background re-summarise.

### Injection points

- **v1 = plan generation only.** `TasksGenerator::buildPrompt()` appends a
  `## Selected context assets` block from
  `$story->includedContextItems()`. The block is hard-capped at 8 KB —
  items past the cap are dropped with a truncation marker.
- **Subtask execution** (`SelectedAssetsContextBuilder` +
  `CompositeContextBuilder`) is **built but not default-wired**. Operators
  opt in by setting `SPECIFY_CONTEXT_BUILDER=composite`. The default
  remains `recency` — runtime behaviour for existing deployments is
  unchanged.

## Consequences

**Approval semantics stay clean.** The same revision-bump path used by
acceptance criteria and scenarios now also covers selected context. The
`bulkSet`-once-per-save contract avoids approval-reopen storms on the
picker.

**Plan-generation prompts can grow.** Plans regenerated with rich context
bundles will produce more tightly-scoped Tasks, but only up to the 8 KB
cap. Reviewers should expect a UI warning when they're about to overflow.

**File leakage on delete.** Soft-delete + hard-file-delete is asymmetric:
the audit row survives but the bytes are gone. This is intentional — we
do not want abandoned PDFs or screenshots accumulating on the disk. When
this moves to S3, the equivalent is a single-version delete (no version
history retention).

**No automatic re-summarisation.** A summarisation that finished `Failed`
stays Failed until a user edits the body. Acceptable in v1; revisit if
operators see flapping providers.

**Project-scoped CRUD leaks edits to included Stories.** A project owner
who edits a referenced Style Guide will not reopen the Stories that cite
it. Document this in the picker copy ("changes to project-scoped items
do not reopen Story approval"). Revisit if reviewers report drift.
