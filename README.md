# Specify

Laravel 13 system where humans approve AI actions on code repos. AI proposes stories and edits, humans gate them, AI executes against real git repos.

## Quickstart

```bash
composer setup            # install deps, copy .env, key:generate, migrate, npm build
composer dev              # serve + queue + pail + vite, all at once
composer test             # pint --test + php artisan test
```

Requires PHP `^8.4`, Node, and SQLite by default. `composer setup` creates `database/database.sqlite` and runs migrations on first run.

## Domain at a glance

`Workspace → Project → Feature → Story → Task → Subtask` (with `AcceptanceCriterion` on Story; one `Task` per AC; each `Task` has 1+ `Subtask`s).

- **Workspace** — tenant boundary, owned by a User. `Team` is workspace-scoped (M:N via `team_user`).
- **Story** — product-owner unit of value; carries `revision` (auto-bumps on edit), `description`, `notes`, and acceptance criteria.
- **Task** — engineering contract for one acceptance criterion. Subtasks are the executor's step list.
- **Approval is the core reframe.** `ApprovalPolicy` (Story/Project/Workspace cascade, configurable `required_approvals` threshold) plus `StoryApproval` (immutable audit log). `ApprovalService::recordDecision/recompute` runs the state machine. **Story is the only approval gate** — Tasks and Subtasks don't gate; the diff-review surface is the PR.

See `docs/adr/` for the load-bearing decisions in detail.

## How a run works

```
Story approved
  → Tasks generated (GenerateTasksJob → TasksGenerator agent, structured output)
  → Story re-approved (any task edit resets to PendingApproval)
  → Subtasks dispatched (ExecuteSubtaskJob)
      → SubtaskRunPipeline: prepare workdir → checkout branch → executor edits
      → commit → diff → push → open PR
  → mark Done → cascade
```

`AgentRun` records every dispatch (polymorphic `runnable`, polymorphic `authorizing_approval`, append-only).

## Pluggable executors

`Executor` interface — `needsWorkingDirectory()`, `execute(Subtask, ?workingDir, ?Repo, ?workingBranch): ExecutionResult`.

- `LaravelAiExecutor` — describe-only, wraps the `TaskExecutor` agent (no fs mutation).
- `CliExecutor` — generic; runs any one-shot agent CLI (claude, codex, gemini, aider) in cwd, observes via `git status`.
- `FakeExecutor` — test double.

Bound by `specify.executor.driver`. See `config/specify.php` for all knobs (`runs_path`, `git.{name,email}`, `workspace.{push_after_commit, open_pr_after_push}`, `github.api_base`, `executor.{driver, cli.{command, timeout}}`).

## Repos

`Repo` is workspace-scoped with a `provider` enum (Github/Gitlab/Bitbucket/Generic) and encrypted `access_token` / `webhook_secret`. M:N with `Project` via `project_repo` (`role`, `is_primary`).

`PullRequestProvider` interface; drivers exist for GitHub, GitLab, and Bitbucket. `ExecuteSubtaskJob` opens a PR after push (config-gated); failures are recorded as `pull_request_error` and don't fail the run.

Branch naming: `specify/story-{id}-v{revision}-task-{position}`.

## Where to look

| Area | Path |
|------|------|
| Approval state machine | `app/Services/ApprovalService.php` |
| MCP tool surface | `app/Mcp/Tools/` |
| Executors | `app/Services/Executors/` |
| Pull request providers | `app/Services/PullRequests/` |
| Git workdir lifecycle | `app/Services/WorkspaceRunner.php` |
| Run pipeline | `app/Services/SubtaskRunPipeline.php` |
| Run orchestration | `app/Jobs/GenerateTasksJob.php`, `app/Jobs/ExecuteSubtaskJob.php` |
| Status enums | `app/Enums/` |
| Architecture decisions | `docs/adr/` |
| Config | `config/specify.php` |

## Agent contributors

`AGENTS.md` (and its mirror `CLAUDE.md`) carry the Laravel Boost guidelines this repo follows. Read this README first for project context, then those for framework conventions.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, test, style, and ADR conventions.

## License

MIT — see [LICENSE](LICENSE).
