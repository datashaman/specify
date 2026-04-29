# Specify — Cliff Notes

**What it is:** Laravel 13 system where humans approve AI actions on code repos. AI proposes plans/edits, humans gate them, AI executes against real git repos.

## Hierarchy
`Workspace → Project → Feature → Story → Plan → Task` (with `AcceptanceCriterion` on Story)
- Story has `revision` (auto-bumps on edit)
- Tasks/Stories support DAG dependencies (cycle-checked)
- Status enums on every level

## Tenancy & people
- `Workspace` — tenant boundary, owned by a User
- `Team` — workspace-scoped, M:N with Users via `team_user` (role enum)
- `User.current_team_id` — auto-provisioned on registration (Fortify)

## Approval system (the core reframe)
- `ApprovalPolicy` with `scope_type/scope_id` — Story/Project/Workspace cascade, `required_approvals` threshold
- `StoryApproval` / `PlanApproval` — concrete tables, immutable audit log
- `ApprovalService::recordDecision/recompute` — state machine: Approve counts unique, Reject is terminal, ChangesRequested resets, Revoke cancels prior
- Story needs approval (configurable threshold), Plan needs approval. Tasks don't (too low-level).

## AI execution layer
- `AgentRun` — polymorphic `runnable` (Plan or Task), polymorphic `authorizing_approval`, `repo_id`, `working_branch`, status enum, JSON input/output. Append-only.
- `ExecutionService` — `dispatchPlanGeneration`, `dispatchTaskExecution`, advances cascade
- `GeneratePlanJob` → `PlanGenerator` agent (laravel/ai, Anthropic, structured output) → creates Plan + Tasks + deps in txn
- `ExecuteTaskJob` orchestrates: prepare workdir → checkout branch → executor → commit → diff → push → open PR

## Repos (M:N with Project)
- `Repo` — workspace-scoped, `provider` enum (Github/Gitlab/Bitbucket/Generic), encrypted `access_token`/`webhook_secret`
- `project_repo` pivot — `role` ("backend"/"server"/"worker"), `is_primary`
- `Project::attachRepo/setPrimaryRepo/primaryRepo`

## Executors (pluggable)
- `Executor` interface — `needsWorkingDirectory()`, `execute(Task, ?dir, ?Repo, ?branch): {summary, files_changed, commit_message}`
- `LaravelAiExecutor` — describe-only (no fs mutation), wraps `TaskExecutor` agent
- `CliExecutor` — generic; runs any one-shot agent CLI (claude/codex/gemini/aider) in cwd, observes via `git status`
- Bound by `specify.executor.driver` config

## Git layer
- `WorkspaceRunner` — `prepare` (clone-or-fetch), `checkoutBranch`, `commit`, `diff`, `push`, `cleanup`. Token injected into HTTPS URLs only.
- Branch naming: `specify/story-{id}-v{version}-task-{position}`

## Pull Requests (just shipped)
- `PullRequestProvider` interface
- `GithubPullRequestProvider` — Http facade, parses owner/repo from URL, requires token
- `PullRequestManager::for(Repo)` — returns driver or null by provider
- `ExecuteTaskJob` opens PR after push (config-gated); failures recorded as `pull_request_error`, don't fail the run

## Config (`config/specify.php`)
`runs_path`, `git.{name,email}`, `workspace.{push_after_commit, open_pr_after_push}`, `github.api_base`, `executor.{driver, cli.{command, timeout}}`

## Test status
100 tests / 236 assertions green across the full filtered suite.

## Loop today
Story approved → Plan generated (AI) → Plan approved → Tasks dispatched → for each: clone, branch, executor edits, commit, diff, push, open PR → mark Done → cascade.
