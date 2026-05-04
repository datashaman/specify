# Repository integration

Specify connects Projects to git repositories, runs AI work in repo-backed
workspaces, opens pull requests, and uses host webhooks to keep PR state and
review-response loops inside the app.

This page describes the current implementation shape. Related decisions are
[ADR-0004](../adr/0004-pr-after-push-is-non-fatal.md),
[ADR-0008](../adr/0008-pr-review-feedback-via-webhooks.md), and
[ADR-0010](../adr/0010-cancel-and-retry-for-agent-runs.md). Run execution is
covered by [AgentRun lifecycle](agent-run-lifecycle.md); Project ownership is
covered by [Project information architecture](project-information-architecture.md).

## Repo model

`Repo` is workspace-scoped. A Repo can attach to one or more Projects in the
same Workspace through `project_repo`.

Stored Repo fields include:

- `workspace_id`
- `name`
- `provider`
- `url`
- `default_branch`
- encrypted `access_token`
- encrypted `webhook_secret`
- `review_response_enabled`
- `max_review_response_cycles`
- `metadata`

Provider selection lives on the Repo:

| Surface | Method | Current drivers |
|---|---|---|
| Pull request creation | `Repo::pullRequestProvider()` | GitHub, GitLab, Bitbucket |
| Advisory review posting | `Repo::reviewProvider()` | GitHub |
| GitHub owner/repo parsing | `Repo::ownerRepo()` | GitHub only |

Unsupported providers return null for provider-specific surfaces. Callers must
treat that as "skip this integration," not as run failure.

## Project attachment

Projects attach Repos through `Project::attachRepo()`.

Attachment rules:

- Repo must belong to the same Workspace as the Project.
- First attached Repo becomes primary automatically.
- Passing `primary=true` makes the new Repo primary and clears the previous
  primary.
- `Project::setPrimaryRepo()` only accepts already-attached Repos.

The primary Repo is the default execution Repo for Subtask runs:

```text
Subtask -> Task -> Plan -> Story -> Feature -> Project -> primaryRepo()
```

Repo management surfaces:

| Surface | Responsibility |
|---|---|
| Project Repos page | Human repo attachment, primary selection, removal. |
| `add-github-repo-to-project` | Creates or reuses a workspace GitHub Repo, installs webhook when possible, and attaches it to a Project. |
| `set-primary-repo` | Marks an attached Repo primary. |
| `remove-project-repo` | Deletes GitHub webhook when applicable, detaches the Repo from Projects, then deletes the Repo row. |

Repo management requires project approver/manage rights.

## GitHub OAuth and webhooks

GitHub Repos are normally created from the acting user's GitHub OAuth token.
`AddGithubRepoToProjectTool` looks up the repo in `GithubRepoCatalog`, stores
the repo URL, default branch, provider, and token, and then attempts webhook
installation.

Webhook installation:

- requires a GitHub provider Repo
- requires an access token
- requires a parseable GitHub `owner/repo`
- requires `admin:repo_hook`
- generates `webhook_secret` if missing
- creates or updates a hook pointing at `route('webhooks.github', $repo)`
- currently asks GitHub to deliver `pull_request` events
- stores `github_hook_id` and `github_hook_url` in `metadata`

Missing `admin:repo_hook` is not treated as a hard attach failure in the MCP
flow. The Repo can still be attached and used for execution; webhook-driven
features simply will not work until the hook is configured.

The controller can handle review events when GitHub sends them, but automatic
hook installation currently subscribes only to `pull_request`. Review-response
automation therefore depends on the hook being configured to deliver
`pull_request_review` and `pull_request_review_comment` events.

Webhook deletion is best-effort. A 404 from GitHub is accepted as already gone;
other failures are logged but do not block local cleanup.

## Pull request creation

`SubtaskRunPipeline` opens a PR after a successful commit and push when:

- `specify.workspace.push_after_commit` is true
- `specify.workspace.open_pr_after_push` is true
- the run has a Repo
- the Repo has a pull request provider

`PullRequestProvider` implementations expose:

- `createPullRequest()`
- `findOpenPullRequest()`

PR title and body come from `PrPayloadBuilder`. The body includes Story,
acceptance criterion, executor summary, changed files, clarifications, appended
Subtasks, and the human-review footer.

PR creation is non-fatal. If the provider throws, the run still succeeds and
the error is recorded in `AgentRun.output.pull_request_error`. Successful PR
creation records `pull_request_url` and `pull_request_number`.

Retrying PR open uses `OpenPullRequestJob`:

- only applies to succeeded runs without `pull_request_url`
- serialises per run with a cache lock
- re-checks the run output inside the lock
- tries to adopt an existing open PR for the branch before creating another
- preserves the terminal run status and only updates output

GitHub PR lookup intentionally lists open PRs and matches `head.ref`
client-side because same-repo branch names with slashes are unreliable through
GitHub's filtered `head=user:branch` query.

## Webhook intake

GitHub webhooks arrive at:

```text
POST /webhooks/github/{repo}
```

`GithubWebhookController` validates `X-Hub-Signature-256` before treating a
delivery as legitimate. Invalid-signature attempts are stored with
`signature_valid=false` and `delivery_id=null`; this prevents an attacker from
occupying the unique delivery slot for a valid future delivery.

Signature-valid deliveries are stored in `webhook_events` with:

- Repo
- provider
- event
- action
- delivery ID
- payload
- signature validity
- matched AgentRun when one is found

`webhook_events.delivery_id` is unique when present. Duplicate valid deliveries
return a duplicate response instead of re-processing the event.

Current event handling:

| GitHub event | Action handling |
|---|---|
| `pull_request` | Matches the originating Execute run by PR number and stamps PR action / merge data into run output. |
| `pull_request_review` | On submitted reviews with body or non-approval state, dispatches review response when enabled. |
| `pull_request_review_comment` | On created comments, dispatches review response when enabled. |

Events that do not match an originating Execute run are acknowledged with
`matched=false`.

## Review responses

Review-response runs are opt-in per Repo:

- `review_response_enabled`
- `max_review_response_cycles`

When a review event is delivered and actionable, the controller asks
`ExecutionService::dispatchReviewResponse()` to create a `respond_to_review`
AgentRun. The controller does not create AgentRuns directly.

Dispatch rules:

- lock the Repo row inside a transaction
- count existing response cycles for the PR
- stop when `max_review_response_cycles` is reached
- create a queued `respond_to_review` run using the originating Execute run's
  Subtask, Repo, branch, and executor driver

`RespondToPrReviewJob`:

- prepares the same workspace and branch
- fetches review summary and inline comments
- runs `ReviewResponder`
- commits and pushes a `fix(review): ...` commit when there is a diff
- marks the response run succeeded even when there is no diff but the response
  contains clarifications
- does not open a new PR

Review-response runs do not affect Subtask completion. The original Execute
run already decided delivery state.

## Advisory reviews and probes

Advisory ADR-conformance review uses `ReviewProvider`.

Current shape:

- GitHub is the only advisory review driver.
- `ReviewPullRequestJob` posts comment-style reviews only.
- Failures are recorded as `review_error` on the AgentRun output.
- Advisory review never changes run status, approval state, or mergeability.

Read-only GitHub probes live in `GithubPullRequestProbe`.

Current probe behavior:

- mergeability probing is best-effort and request-memoized
- mergeability failures return null and log
- conflict-resolution comments are best-effort issue comments
- unsupported providers return null/false

## Invariants to preserve

- Do not attach a Repo to a Project in another Workspace.
- Do not allow more than one primary Repo per Project.
- Do not fail an AgentRun because PR creation failed.
- Do not create AgentRuns directly from webhook controllers.
- Do not process invalid-signature webhook deliveries as idempotent successes.
- Do not let duplicate GitHub deliveries dispatch duplicate review-response
  runs.
- Do not assume the auto-installed webhook receives review events unless its
  GitHub event list includes them.
- Do not let review-response runs affect Subtask completion.
- Do not make advisory reviews blocking.
- Do not assume every Repo provider supports every integration surface.
