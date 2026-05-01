# 0004. Opening the pull request after push is a non-fatal step

Date: 2026-05-01
Status: Accepted

## Context

After a Subtask runs the executor, commits the diff, and pushes the working branch, the pipeline tries to open a pull request via the configured `PullRequestProvider`. PR creation can fail for reasons unrelated to the engineering work itself: missing `repo` scope on the access token, GitHub API rate limits, network blips, a webhook that hasn't propagated yet, or an admin policy that blocks the bot account from opening PRs.

If we let any of these failures fail the entire run, we'd throw away successful work — the commit is already on the remote branch, a human could open the PR manually in seconds.

## Decision

Opening the PR is a **non-fatal terminal step**:

- The pipeline performs prepare → checkout → execute → commit → diff → push as normal.
- It then attempts `PullRequestManager::for($repo)?->open(...)` (driver returned only when the `Repo`'s provider has one).
- On failure, the error is recorded on the `AgentRun` as `pull_request_error` (and surfaced in the run output JSON) but the run still terminates as `Succeeded`.
- The branch and commit remain on the remote; the operator can open the PR manually, retry via a future tool, or fix the root cause and resume.
- PR opening is also gated by `specify.workspace.open_pr_after_push`, so an operator can disable it entirely.

## Consequences

Easier:
- A misconfigured token or transient API failure doesn't lose engineering work.
- The "did the executor produce a usable diff?" question is decoupled from "is the PR API cooperating?".
- Operators in air-gapped or self-hosted-git setups can leave `open_pr_after_push` off and live without a PR provider entirely.

Harder / accepted trade-offs:
- Run status alone doesn't tell you whether a PR is open — callers must check `pull_request_error` or the PR URL field on the run output.
- A run can succeed with no PR, which means downstream automation expecting a PR URL needs to handle the empty case.

Follow-ups:
- A dedicated retry tool / job to open the PR for a successful run that didn't get one.
- Surfacing `pull_request_error` prominently in any UI that lists runs.
