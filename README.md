# Specify

Laravel 13 system where humans approve AI actions on code repos. AI proposes plans and edits, humans gate them, AI executes against real git repos.

## Quickstart

```bash
composer setup            # install deps, copy .env, key:generate, migrate, npm build
composer dev              # serve + queue + pail + vite, all at once
composer test             # pint --test + php artisan test
```

Requires PHP `^8.4`, Node, and SQLite by default. `composer setup` creates `database/database.sqlite` and runs migrations on first run.

## Domain at a glance

`Workspace → Project → Feature → Story → Plan → Task` (with `AcceptanceCriterion` on Story, `Subtask` under Task).

- **Workspace** — tenant boundary, owned by a User. `Team` is workspace-scoped (M:N via `team_user`).
- **Story** — product-owner unit of value; carries `revision` (auto-bumps on edit) and acceptance criteria.
- **Plan** — the engineering breakdown of a story (one `Task` per acceptance criterion, each with 1+ `Subtask`s).
- **Approval is the core reframe.** `ApprovalPolicy` (Story/Project/Workspace cascade, configurable threshold) plus `StoryApproval`/`PlanApproval` immutable audit tables. `ApprovalService::recordDecision/recompute` runs the state machine. Stories and plans gate; tasks don't.

## How a run works

```
Story approved
  → Plan generated (GeneratePlanJob → PlanGenerator agent, structured output)
  → Plan approved
  → Tasks dispatched (ExecuteTaskJob)
      → prepare workdir → checkout branch → executor edits
      → commit → diff → push → open PR
  → mark Done → cascade
```

`AgentRun` records every dispatch (polymorphic `runnable`, polymorphic `authorizing_approval`, append-only).

## Pluggable executors

`Executor` interface — `needsWorkingDirectory()`, `execute(Task, ?dir, ?Repo, ?branch)`.

- `LaravelAiExecutor` — describe-only, wraps the `TaskExecutor` agent.
- `CliExecutor` — generic; runs any one-shot agent CLI (claude, codex, gemini, aider) in cwd, observes via `git status`.

Bound by `specify.executor.driver`. See `config/specify.php` for all knobs (`runs_path`, `git.{name,email}`, `workspace.{push_after_commit, open_pr_after_push}`, `github.api_base`, `executor.{driver, cli.{command, timeout}}`).

## Repos

`Repo` is workspace-scoped with a `provider` enum (Github/Gitlab/Bitbucket/Generic) and encrypted `access_token` / `webhook_secret`. M:N with `Project` via `project_repo` (`role`, `is_primary`).

`PullRequestProvider` interface; `GithubPullRequestProvider` is the only driver today. `ExecuteTaskJob` opens a PR after push (config-gated); failures are recorded as `pull_request_error` and don't fail the run.

Branch naming: `specify/story-{id}-v{version}-task-{position}`.

## Where to look

| Area | Path |
|------|------|
| Approval state machine | `app/Services/ApprovalService.php` |
| MCP tool surface | `app/Mcp/Tools/` |
| Executors | `app/Executors/` |
| Git workdir lifecycle | `app/Services/WorkspaceRunner.php` |
| Run orchestration | `app/Jobs/ExecuteTaskJob.php`, `app/Jobs/GeneratePlanJob.php` |
| Status enums | `app/Enums/` |
| Config | `config/specify.php` |

## Agent contributors

`AGENTS.md` (and its mirror `CLAUDE.md`) carry the Laravel Boost guidelines this repo follows. Read this README first for project context, then those for framework conventions.

## License

MIT — see `composer.json`. (A top-level `LICENSE` file is on the to-do list.)
