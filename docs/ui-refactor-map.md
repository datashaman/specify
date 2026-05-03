# UI Refactor Map

This document summarizes the Blade/Flux refactor that reduced duplicated UI markup across the app.

## Goals

- extract repeated story, run, subtask, and menu markup into reusable components
- split the largest page (`resources/views/pages/stories/⚡show.blade.php`) into smaller partials
- improve consistency across list/detail surfaces
- fix missing/implicit UI dependencies discovered during validation

## New components

### Story components

#### `resources/views/components/story/summary-card.blade.php`
Generic story summary card for list-style views.

**Used in:**
- `resources/views/pages/stories/⚡index.blade.php`
- `resources/views/pages/⚡triage.blade.php`

#### `resources/views/components/story/feature-row.blade.php`
Feature-scoped story row with status rail, revision, task tally, avatar, and optional reorder handle.

**Used in:**
- `resources/views/pages/features/⚡show.blade.php`

### Run components

#### `resources/views/components/run/summary-card.blade.php`
Reusable run card with:
- full mode for detailed run lists
- compact mode for dashboard-style rows

**Used in:**
- `resources/views/pages/runs/⚡index.blade.php`
- `resources/views/pages/⚡dashboard.blade.php`

#### `resources/views/components/run/list-row.blade.php`
Compact bordered run row for subtask pages.

**Used in:**
- `resources/views/pages/subtasks/⚡show.blade.php`

#### `resources/views/components/run/error-output.blade.php`
Reusable error disclosure/panel.

**Used in:**
- `resources/views/partials/story-task.blade.php`
- `resources/views/pages/runs/⚡show.blade.php`
- `resources/views/components/run/history-panel.blade.php`

#### `resources/views/components/run/history-panel.blade.php`
Reusable prior-run history block for subtasks.

**Used in:**
- `resources/views/partials/story-task.blade.php`

#### `resources/views/components/run/log-panel.blade.php`
Reusable logs/stdout/stderr renderer.

**Used in:**
- `resources/views/pages/runs/⚡show.blade.php`

### Subtask components

#### `resources/views/components/subtask/summary-row.blade.php`
Reusable subtask list row with rail, state, latest run badge, and timestamp.

**Used in:**
- `resources/views/pages/tasks/⚡show.blade.php`

### Repo components

#### `resources/views/components/repo/summary-card.blade.php`
Reusable repository summary card for project repository management.

**Used in:**
- `resources/views/pages/repos/⚡index.blade.php`

### User/menu components

#### `resources/views/components/user/menu-content.blade.php`
Shared account/settings/logout menu content.

**Used in:**
- `resources/views/livewire/user-menu.blade.php`

## New partials

### `resources/views/partials/story-show/header.blade.php`
Story title, status metadata, delete modal, and pull-request strip.

**Used in:**
- `resources/views/pages/stories/⚡show.blade.php`

### `resources/views/partials/story-show/plan.blade.php`
Acceptance-criteria/task/subtask plan section, density toggle, and plan-generation runs.

**Used in:**
- `resources/views/pages/stories/⚡show.blade.php`

### `resources/views/partials/story-show/decision-rail.blade.php`
Approval/decision action rail, decision log, and eligible approvers.

**Used in:**
- `resources/views/pages/stories/⚡show.blade.php`

## Existing components now used more consistently

### `resources/views/components/state-pill.blade.php`
Shared state presentation across story/task/run/subtask contexts.

### `resources/views/components/decision-row.blade.php`
Shared approval log row in story detail.

### `resources/views/components/rail.blade.php`
Shared visual state rail for rows and cards.

## Files with the biggest change

### `resources/views/pages/stories/⚡show.blade.php`
Changed from a large monolithic page into a composed page that delegates to:
- `partials/story-show/header`
- `partials/story-show/plan`
- `partials/story-show/decision-rail`

### `resources/views/pages/runs/⚡index.blade.php`
Now renders run cards via `x-run.summary-card`.

### `resources/views/pages/features/⚡show.blade.php`
Now renders feature story rows via `x-story.feature-row`.

### `resources/views/pages/tasks/⚡show.blade.php`
Now renders subtask rows via `x-subtask.summary-row`.

### `resources/views/livewire/user-menu.blade.php`
Compact and non-compact menu variants now share `x-user.menu-content`.

## Validation

The refactor was validated with:

- `php artisan test`
- `php artisan view:cache`

The validation also surfaced and fixed a missing component reference in:

- `resources/views/layouts/app/header.blade.php`

`<x-desktop-user-menu />` was replaced with the existing Livewire menu component:

- `<livewire:user-menu :compact="false" :key="'header-user-menu-desktop'" />`

## Remaining follow-up opportunities

The highest-value remaining DRY opportunities are:

- `resources/views/partials/story-task.blade.php`
  - could be split further into task header/body and subtask-specific partials
- `resources/views/pages/settings/⚡two-factor-setup-modal.blade.php`
