# Specify — Agent Onboarding

Specify is a Laravel 13 system where humans approve AI actions on code repos. **Read [README.md](README.md) first** for the project tour and [docs/adr/](docs/adr/) for the load-bearing decisions.

`CLAUDE.md` is a symlink to this file — Claude Code and Codex CLI both load the same content.

## Where to start

| You're about to... | Read first |
|---|---|
| Touch the approval state machine | [ADR-0001](docs/adr/0001-story-and-plan-approval-gates.md), `app/Services/ApprovalService.php` |
| Edit Story / criteria / scenarios / Plan / Task / Subtask | [ADR-0002](docs/adr/0002-story-scenario-plan-task-subtask-hierarchy.md), `app/Services/PlanWriter.php` |
| Add or change an Executor | [ADR-0003](docs/adr/0003-pluggable-executor-interface.md), `app/Services/Executors/` |
| Race executors against each other | [ADR-0006](docs/adr/0006-multi-executor-race-mode.md), `config/specify.php` (`executor.race`), `App\Services\Executors\ExecutorFactory` |
| Touch PR creation | [ADR-0004](docs/adr/0004-pr-after-push-is-non-fatal.md), `app/Services/PullRequests/` |
| Touch advisory PR review | `app/Services/Reviews/` (`ReviewProvider`, `GithubReviewProvider`), `app/Jobs/ReviewPullRequestJob.php` — gated on `SPECIFY_REVIEW_ENABLED`, never blocks merge |
| Add an MCP tool | `app/Mcp/Tools/` (follow the existing `#[Description]` + `handle()` shape) |
| Edit an agent's system prompt | `prompts/*.md` (loaded by `App\Services\Prompts\PromptLoader`) |
| Tune the per-subtask context brief | `app/Services/Context/` (`ContextBuilder` interface + `RecencyContextBuilder`) |
| Run the suite | `composer test` (Pint check + Pest) |

## Specify-specific rules

- **StoryApproval gates the product contract.** Story body, criteria, and scenarios are approved at Story level — see ADR-0001.
- **PlanApproval gates the current implementation plan.** Execution requires an approved Story and approved current Plan. Don't add per-Task or per-Subtask approval flows.
- **Plan is active.** Tasks attach to Plans; Subtasks live under Tasks. Resolve Story through `Task -> Plan -> Story`, not `Task -> Story`.
- **Editing product contract reopens Story and Plan approval.** Editing the current Plan, Tasks, or Subtasks reopens Plan approval. Route plan replacements through `PlanWriter::replacePlan()` so the invariant lives in one place.
- **PR opening is non-fatal.** A failed PR creation must record `pull_request_error` on the AgentRun and let the run still succeed (ADR-0004). Don't fail the run on PR errors.
- **`AgentRun` is append-only.** `StoryApproval` and `PlanApproval` are immutable. Treat them as audit logs — corrections happen by writing new rows, never by mutating old ones.
- **"The database" means the Laravel app DB.** Per-run git working directories under `storage/app/runs/...` are filesystem state, not database state.

## Framework guidance — ask Boost

For framework questions, use Boost's MCP tools rather than a pre-loaded cheat-sheet:

- `search-docs` — version-specific Laravel / Livewire / Flux / Fortify / Pest / Pint / Tailwind docs.
- `application-info` — installed packages and their versions.
- `database-schema`, `database-query`, `database-connections` — DB inspection.
- `last-error`, `read-log-entries`, `browser-logs` — debugging the running app.
- `get-absolute-url` — correct scheme/host/port for project URLs.

Project-specific conventions (PHPDoc style, test discipline, what changes need an ADR) live in [CONTRIBUTING.md](CONTRIBUTING.md). Domain skills under `**/skills/**` activate automatically when relevant.
