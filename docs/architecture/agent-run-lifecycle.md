# AgentRun lifecycle

An `AgentRun` is one auditable AI dispatch. It can represent task generation,
Subtask execution, review response, or conflict resolution. The row records
which approval context applied, which executor ran, which branch was used,
what changed, and how the run terminated.

This page describes the current implementation shape. The load-bearing
decisions are [ADR-0014](../adr/0014-executor-contract-and-runtime-locality.md),
[ADR-0004](../adr/0004-pr-after-push-is-non-fatal.md),
[ADR-0006](../adr/0006-multi-executor-race-mode.md),
[ADR-0007](../adr/0007-already-complete-subtasks.md),
[ADR-0008](../adr/0008-pr-review-feedback-via-webhooks.md),
[ADR-0010](../adr/0010-cancel-and-retry-for-agent-runs.md), and
[ADR-0011](../adr/0011-streaming-progress-events-from-executors.md).

## Core model

`AgentRun` is append-only for audit purposes. The row may move through status
states, and output fields may be filled as the run produces artefacts, but old
run rows are not deleted or rewritten as a history-compaction mechanism.

Primary fields:

- `runnable_type` and `runnable_id` point at the thing being acted on.
- `repo_id` points at the repository used by workspace-backed runs.
- `working_branch` is the branch the executor writes to.
- `executor_driver` records the concrete executor used for this run.
- `user_id` records the user whose BYOK credential funds the run.
- `kind` records why the run exists.
- `authorizing_approval_type` and `authorizing_approval_id` optionally point
  at the approval row that allowed the run. They may be null when approval was
  satisfied without a concrete approval row, such as auto-approval policies.
- `status` records queued, running, or terminal state.
- `input`, `output`, `diff`, token counts, and timestamps record run artefacts.
- `cancel_requested` records a cooperative cancel request.
- `retry_of_id` links a retry to the failed, cancelled, or aborted run it
  replaces.

`AgentRunStatus` values:

| Status | Meaning |
|---|---|
| `queued` | The run row exists and the queue job has been dispatched. |
| `running` | The worker has claimed the run and stamped `started_at`. |
| `succeeded` | The run completed successfully. |
| `failed` | The run failed, including no-diff execution failures. |
| `aborted` | An operator force-aborted a stuck run. |
| `cancelled` | A user requested cooperative cancellation and the run stopped. |

Terminal statuses are `succeeded`, `failed`, `aborted`, and `cancelled`.

`AgentRunKind` values:

| Kind | Runnable | Purpose | Cascade effect |
|---|---|---|---|
| `execute` | `Story` or `Subtask` | Generate a Story's current Plan, or produce a Subtask implementation diff, commit it, push it, and open a PR. | Subtask Execute runs count toward Subtask completion. Story plan-generation runs do not. |
| `respond_to_review` | `Subtask` | Push a follow-up commit addressing PR review comments on an existing branch. | Ignored by the Subtask cascade. |
| `resolve_conflicts` | `Subtask` | Repair merge conflicts on the Story's primary PR branch. | Ignored by the Subtask cascade. |

Plan-generation runs use a `Story` runnable. They require the Story to be in
an approval-satisfied state and are handled by `GenerateTasksJob`. When a
concrete `StoryApproval` row authorised the generation, the run records it;
auto-approval paths may leave `authorizing_approval` null.

## Ownership boundaries

The lifecycle is split across a few focused services and jobs.

| Module | Responsibility |
|---|---|
| `App\Services\ExecutionService` | Public orchestration surface for dispatch, status transitions, retry, cancel, PR retry, review response, and conflict resolution. |
| `App\Services\SubtaskExecutionScheduler` | Creates Subtask execution runs, fans out race-mode siblings, finds actionable Subtasks, and advances the Subtask -> Task -> Plan -> Story cascade. |
| `App\Services\SubtaskRunPipeline` | Executes one Subtask run through prepare, context brief, executor, commit, push, PR, already-complete handling, progress events, and proposed Subtasks. |
| `App\Services\WorkspaceRunner` | Owns git workspace preparation, branch checkout, commit, diff, push, merge, reset, and cleanup operations. |
| `App\Services\Executors\ExecutorFactory` | Resolves the executor driver named on each run. |
| `App\Jobs\ExecuteSubtaskJob` | Queue lifecycle wrapper for one Execute run. |
| `App\Jobs\OpenPullRequestJob` | Retries PR opening for a succeeded run whose PR step failed. |
| `App\Jobs\ReviewPullRequestJob` | Posts optional advisory ADR-conformance review comments after a PR opens. |
| `App\Jobs\RespondToPrReviewJob` | Pushes a follow-up commit for webhook-driven review feedback. |
| `App\Jobs\ResolveConflictsJob` | Resolves merge conflicts on the existing PR branch, or records stale mergeability when no repair is needed. |

`ExecutionService` is the write API callers should use. Queue jobs and UI
actions should not hand-edit run terminal state because the transition methods
also trigger cascade finalisation where appropriate.

## Execute run path

The normal Subtask execution path is:

```text
approved Story + approved current Plan
  -> ExecutionService::startStoryExecution()
  -> SubtaskExecutionScheduler::nextActionableSubtasks()
  -> one or more Execute AgentRuns are queued
  -> ExecuteSubtaskJob marks the run Running
  -> SubtaskRunPipeline prepares the workspace
  -> ContextBuilder creates the per-Subtask context brief
  -> executor runs against the Subtask and workspace
  -> proposed follow-up Subtasks are appended when present
  -> WorkspaceRunner commits and diffs the work
  -> branch is pushed
  -> pull request is opened
  -> ExecuteSubtaskJob records Succeeded or Failed
  -> SubtaskExecutionScheduler finalises the Subtask
```

Execution is gated on the current product and implementation approvals:

- Story status must be `approved`.
- The Story must have a current Plan.
- The current Plan must be approved.
- Execute runs require the current Plan to be approved. When a concrete
  `PlanApproval` row authorised execution, the run records it; auto-approval
  paths may leave `authorizing_approval` null.

Subtasks become actionable only when their parent Task dependencies are done
and the prior Subtasks in that Task are done. The scheduler dispatches the next
pending Subtask in each unblocked Task, so parallelism happens across
independent Tasks, not inside one ordered Task.

## Executor selection

`executor_driver` is stamped on every Execute run. `ExecuteSubtaskJob` resolves
that driver through `ExecutorFactory` immediately before running the pipeline.
The factory also enforces executor locality: hosted runtime runs drivers
declared as remote-safe in `config/specify.php`, plus any local driver names
explicitly listed in `specify.runtime.remote_executors` for deployments where
the operator has provided a remote-safe worker.

Single-driver mode creates one Execute run per Subtask using the default
driver. Race mode creates one sibling Execute run per configured race driver.
Each sibling:

- has the same Subtask runnable
- records its own `executor_driver`
- records the triggering `user_id`
- uses its own branch suffix
- opens its own PR
- terminates independently

The cascade waits until all Execute siblings for the Subtask are terminal.
The Subtask is `done` if any sibling succeeded, and `blocked` if none did. The
reviewer chooses the winning implementation by merging a PR; merge state is not
stored as a special run status.

## Workspace and branch model

Workspace-backed runs use `WorkspaceRunner`.

Subtask runs with a Feature and Story slug share:

```text
{specify.runs_path}/specify/{feature-slug}/{story-slug}
```

Runs that cannot resolve that shape fall back to:

```text
{specify.runs_path}/run-{agent-run-id}
```

The normal Execute branch is:

```text
specify/{feature-slug}/{story-slug}
```

Race-mode branches add the driver suffix:

```text
specify/{feature-slug}/{story-slug}-by-{driver}
```

Before execution, checkout resets from the remote branch when it exists. This
keeps repeated runs on the same branch from working against stale local state.
Cooperative cancellation discards local-only changes so a future retry does not
start from a partial cancelled commit.

## Pipeline outcomes

`SubtaskRunPipeline` returns a `SubtaskRunOutcome`. `ExecuteSubtaskJob`
translates the outcome into an `ExecutionService` status transition.

| Outcome | Run status | Notes |
|---|---|---|
| `succeeded` | `succeeded` | Executor produced acceptable work and the run artefacts were recorded. |
| `pull_request_failed` | `succeeded` | Commit and push succeeded; PR opening failed non-fatally and `pull_request_error` was recorded. |
| `already_complete` | `succeeded` | Executor produced no diff but cited reachable commit evidence proving the Subtask was already satisfied. |
| `no_diff` | `failed` | Executor produced no diff and did not prove already-complete evidence. |
| `cancelled` | `cancelled` | Pipeline observed `cancel_requested` between phases. |

PR opening is not part of the success gate for a run. If PR creation fails,
the branch and commit remain published, the run is still `succeeded`, and the
error is stored in `output.pull_request_error`. `OpenPullRequestJob` can retry
that step later without changing the terminal run status.

## Context brief

`ContextBuilder` creates a bounded per-Subtask markdown brief before the
executor runs. It complements the static prompts in `prompts/`: prompts define
how the agent should work, while the context brief describes what this run is
about to touch.

`RecencyContextBuilder` currently layers:

- files mentioned by the Subtask description and found in the working tree
- recent commits touching those mentioned files
- up to three prior failed runs for the same Subtask

The brief is capped and failure-tolerant. If context generation fails, the run
continues without a brief.

## BYOK

Laravel AI SDK-backed runs resolve credentials from the AgentRun owner. The
user stores encrypted Anthropic or OpenAI keys in Settings, and each AI job
registers an explicit per-run provider config before prompting the agent.
Missing user ownership or missing enabled credentials fails the run before a
provider call is made. Hosted user-triggered work does not fall back to
app-global AI provider keys.

## Progress events

`ProgressEmitter` writes durable `AgentRunEvent` rows. The pipeline sets the
current phase and passes the emitter into the executor.

Phases currently include:

- `prepare`
- `execute`
- `commit`
- `push`
- `open_pr`

`RunEventsController` exposes an HTTP polling endpoint. Clients pass an
`after` cursor and receive ordered events plus the run's current status. These
rows are the durable source of truth; broadcast delivery can layer on top.

## Review and conflict follow-ups

Review and conflict follow-ups reuse the `AgentRun` audit surface but do not
reopen Subtask completion.

Advisory ADR review:

- runs from `ReviewPullRequestJob` after a successful Execute run with a PR
- is gated by `specify.review.enabled` and configured review personas
- posts `COMMENT`-style host reviews only
- records `review_url`, `review_comment_count`, `review_overall`, or
  `review_error` in run output
- never changes run status or approval state

Webhook-driven review response:

- starts from GitHub review webhook handling
- creates a `respond_to_review` run through `ExecutionService`
- uses the originating Execute run's Subtask, repo, branch, and driver
- serialises per repo and branch with a cache lock
- commits and pushes back to the existing PR branch

Conflict resolution:

- starts from a human action on a Story whose primary PR is not mergeable
- creates a `resolve_conflicts` run through `ExecutionService`
- uses the originating Execute run's Subtask, repo, branch, and driver
- merges the default branch into the PR branch
- if the merge succeeds without conflicts, marks the run succeeded with
  `stale_mergeability=true`
- if unmerged paths remain, asks the executor to resolve them
- pushes one repair commit and comments on the PR only when conflict repair
  was actually needed

Both follow-up kinds are terminal audit rows. The Subtask cascade ignores them
because the Execute run already determined Subtask delivery state.

## Retry and cancellation

Cancellation is cooperative:

- terminal runs are no-ops
- queued runs flip directly to `cancelled`
- running runs set `cancel_requested`
- the pipeline observes cancellation at phase boundaries
- cancellation cleanup discards local-only workspace changes

Retries create new rows. `retrySubtaskExecution()` only retries failure-class
Execute runs: `failed`, `cancelled`, or `aborted`. It links the new run to the
prior run through `retry_of_id` and re-resolves approval against the current
approved Plan. If the Story or current Plan is no longer approved, retry is
rejected.

Review-response runs are not retried from the run console; new review events
create new response runs. Conflict-resolution runs are not retried from the run
console; the human action can dispatch another conflict-resolution attempt
subject to the configured cycle cap.

## Completion cascade

Only terminal Execute runs affect Subtask status.

`SubtaskExecutionScheduler::finalizeSubtaskFromRun()`:

1. ignores `respond_to_review` and `resolve_conflicts`
2. locks the Subtask
3. loads all Execute sibling runs for that Subtask
4. returns early if any sibling is still active
5. marks the Subtask `done` if any sibling succeeded
6. marks the Subtask `blocked` if no sibling succeeded
7. advances Task, Plan, and Story completion when all children are done
8. dispatches newly actionable Subtasks when work remains

The cascade is the only place that decides whether a terminal Execute run
advances delivery state. Callers should transition runs through
`ExecutionService::markSucceeded()`, `markFailed()`, `markAborted()`, or
`markCancelled()` so this finalisation always fires.

## Invariants to preserve

- Do not create AgentRuns directly from controllers, webhooks, or MCP tools
  when an `ExecutionService` dispatch method exists.
- Do not delete AgentRun rows.
- Do not treat PR creation failure as run failure.
- Do not let advisory review block merge, approval, or run success.
- Do not let `respond_to_review` or `resolve_conflicts` affect Subtask
  completion.
- Do not retry succeeded Execute runs.
- Do not retry against stale approval state; retries must re-check the current
  approved Plan and record the current approval row when one exists.
- Do not bypass `SubtaskExecutionScheduler::finalizeSubtaskFromRun()` for
  terminal Execute runs.
- Do not share one branch for multiple race-mode drivers.
- Do not let context-brief or proposed-Subtask failures tank an otherwise
  successful run.
