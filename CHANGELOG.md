# Changelog

All notable changes to this project will be documented in this file. The
format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Project documentation: top-level `README.md`, `CONTRIBUTING.md`, `LICENSE` (MIT), and `docs/adr/` with the load-bearing decisions (Story/Plan approval gates, product/delivery hierarchy, Executor interface, PR-after-push as non-fatal).
- PHPDoc on every status/role enum and every MCP `Tool` class with its `handle()` entry point.
- `Subtask` model and `ExecuteSubtaskJob` — the executor's actual unit of work, replacing per-Task execution.
- Slugs on `Project`, `Feature`, and `Story` with inline edit UI on each show page.
- Per-story branch naming under the executor: `specify/{feature-slug}/{story-slug}`.
- Logging across the executor lifecycle and every tool call, so subtask runs are auditable.
- `EditFile` executor tool now matches against the original file content, not the running buffer.
- Surface API errors and empty structured output from the executor as distinct failure modes.

### Changed

- **BREAKING**: restored `Plan` as the implementation interpretation of a Story. Tasks attach to Plans; Subtasks attach to Tasks. See [ADR-0002](docs/adr/0002-story-scenario-plan-task-subtask-hierarchy.md).
- **BREAKING**: restored `PlanApproval`. `StoryApproval` gates the product contract and `PlanApproval` gates execution against the current Plan. See [ADR-0001](docs/adr/0001-story-and-plan-approval-gates.md).
- Approval policy with threshold 0 collapses `PendingApproval` immediately; execution is now gated behind an explicit `start` action.
- Executor stopped swallowing failures: a missing diff or a PR error now fails the run rather than reporting a phantom success.
- Working directory paths now mirror the branch name.
- Executor agent prompt uses real tool names; dead `name()` methods removed from tools.

### CI / tooling

- Dropped PHP 8.3 from CI; project now requires `^8.4`.
- Trimmed redundant workflows.

---

Earlier history (pre-changelog) lives in `git log`. The first tagged release will start a versioned section above this one.
