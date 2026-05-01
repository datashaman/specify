# 0008. PR review feedback closes the loop via webhooks

Date: 2026-05-01
Status: Accepted

## Context

Specify opens PRs at the end of every Subtask AgentRun (ADR-0004). Today
those PRs accumulate review feedback (Copilot, human reviewers) passively
— a human has to come back, read the comments, dispatch some kind of
follow-up, and push a fix. We tried two off-app workarounds:

- A scheduled remote agent polling open PRs every hour. Works, but is
  external to the app's domain model, has no audit trail in
  `agent_runs`, and lags by up to an hour.
- Manual response loops where the human pastes the review into a chat
  with Claude Code. Works, but defeats the point of running an
  orchestrator.

The pattern is loud enough that it should live in the app: when a review
is posted on a PR Specify owns, dispatch an AgentRun whose job is to
address the comments on the same branch.

## Decision

**A new GitHub webhook endpoint (`POST /webhooks/github`) listens for
`pull_request_review` and `pull_request_review_comment` events, looks up
the AgentRun that originated the PR, and (if the repo opted in)
dispatches a fresh AgentRun whose `kind` is `RespondToReview`.**

Concrete shape:

- `agent_runs` gains a `kind` column (default `execute`). Two values
  today: `execute` (the existing run that produces a Subtask's diff)
  and `respond_to_review` (the new flavour that consumes review
  comments and pushes a fix on the same branch).
- The cascade gate in `ExecutionService::finalizeSubtaskFromRun`
  ignores `respond_to_review` runs entirely. The Subtask is already
  Done by the time review comments arrive; review responses neither
  decide nor change the cascade.
- `repos` gains three columns:
  - `webhook_secret` (nullable). The shared secret used to verify
    GitHub's `X-Hub-Signature-256` header. NULL means the repo has
    no webhook configured and incoming events for it are rejected.
  - `review_response_enabled` (boolean, default false). Per-repo
    opt-in switch. The flag has to be flipped explicitly by the
    workspace owner — automatic review-response is not the default
    because it costs AI spend and writes to the host repo.
  - `max_review_response_cycles` (integer, default 3). Cap on how
    many `respond_to_review` runs may be dispatched for a single
    PR. When the cap is hit the webhook is acked but no new run
    fires; reviewers fall back to humans.
- `github_webhook_deliveries (id, delivery_id UNIQUE, repo_id, event,
  action, received_at)` is the idempotency table. The webhook
  controller upserts on `delivery_id` (GitHub's `X-GitHub-Delivery`
  header) and ignores duplicates so an at-least-once webhook
  delivery doesn't fire twice.
- A new agent `App\Ai\Agents\ReviewResponder` runs the
  `respond_to_review` AgentRun. Same tool box as `SubtaskExecutor`
  (Read/Write/Edit/Bash/Grep/Find/Ls), different prompt — the prompt
  takes the originating Subtask spec, the current branch state, and
  the review comments grouped by file/line, and instructs the agent
  to produce focused fixes for each comment (or a `clarification`
  if a comment requires redesign).
- `ReviewResponseRunPipeline` reuses `WorkspaceRunner` (prepare +
  checkoutBranch — the workspace fix from ADR-0006's stack means
  the branch syncs to origin). Commit message convention:
  `fix(review): address PR #N review`. **No PR is opened** — the
  PR is already open; the new commit lands on its head branch.

## Consequences

### Positive

- Review feedback closes the loop inside the domain model.
  `agent_runs` carries the full audit trail (`kind`, originating
  Subtask, commits produced, error messages) so "what happened to
  this PR" is queryable, not lost in chat history.
- Real-time response. Webhook → job → branch update inside seconds
  instead of polling lag.
- Same opt-in shape as the rest of the system — repo-level toggle,
  bounded by a per-PR cap, no new approval surfaces.
- The cap + the `kind` distinction make loop runaway impossible:
  Copilot can't trigger an infinite "review the fix to the review
  to the review" cycle, because the cap counts response runs per
  PR.

### Negative

- AI spend per PR scales with how chatty the reviewer is. The cap
  is the only governor; if `max_review_response_cycles=3` is too
  loose, raise the bar.
- Webhook secrets become a new piece of repo configuration.
  Mitigation: `webhook_secret` is nullable; an unconfigured repo
  silently rejects events and the rest of the system keeps working.
- Public webhook endpoint requires the app to be reachable.
  Mitigation: the endpoint is signature-gated; payloads with no
  matching repo or invalid signature 401 immediately.

### Neutral

- ADR-0001 (Story is the only approval gate) is preserved.
  `respond_to_review` runs address feedback on **already-approved**
  work; they do not introduce new spec, do not change the Story's
  status, and never reset approval. The PR diff-review surface
  (the same one ADR-0001 names) is exactly where the human notices
  if a review-response run did the wrong thing.
- ADR-0004 (PR after push is non-fatal) is unchanged: review-response
  runs don't open a PR, so the PR-failure path doesn't apply.
- ADR-0005 (executor may append follow-up subtasks) does not apply
  here — review responses don't create new Subtasks; they push
  commits onto the originating Subtask's branch.
- ADR-0007 (already-complete subtasks) does not apply — review-
  response runs do not use the no-diff/already-complete machinery;
  if the agent decides every comment is wrong, it returns a
  clarification and the run ends Succeeded with no commit.

## Open questions (future work, not blocking)

- Should review-response runs themselves trigger advisory ADR-conformance
  review? Today only `execute` runs do (gated on `pull_request_number`
  on the run's output). Probably yes once we trust this flow, but
  start it off.
- Should the response agent be allowed to *close* a comment thread
  by replying to it (GitHub API supports this), or only push code?
  Code-only for V1.
- Should we batch reviews — e.g. wait 60 seconds before dispatching
  in case more comments arrive — or fire on every event? Fire-on-every
  for V1; debouncing is an easy follow-up if it gets noisy.
