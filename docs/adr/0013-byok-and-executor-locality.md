# 0013. BYOK and executor locality for hosted deployments

Date: 2026-05-05
Status: Accepted

## Context

Specify can run AI work for many users. In a live deployment, app-global AI
provider keys would make the operator pay for user-triggered work and would
blur audit ownership. Some executors are also inherently local: CLI drivers
such as Claude Code or Codex inherit credentials and installed binaries from
the worker host, so running them on the hosted server is not equivalent to
running them on a user's machine.

## Decision

AI provider credentials are BYOK and user-scoped.

- Users store encrypted Anthropic or OpenAI keys in their own settings.
- `AgentRun.user_id` records the user who owns and funds the run.
- Follow-up runs inherit the originating run owner.
- Laravel AI SDK calls resolve an explicit per-run provider config from the
  run owner's key.
- User-triggered AI work does not fall back to app-global AI provider env keys.

Executor locality is configuration and runtime enforced.

- Each executor driver declares `environment: local|remote`.
- `SPECIFY_RUNTIME_ENV=hosted` allows only `remote` executors.
- Local CLI drivers remain available in local runtime.
- Fake executors are not available in production.

## Consequences

Easier:

- Hosted usage is funded by the user who triggered the run.
- Failed runs can clearly state missing BYOK setup instead of leaking provider
  errors or silently using operator keys.
- Executor availability is explicit and enforced in the backend, not only UI.

Harder / accepted trade-offs:

- Every AI-dispatching path must carry run ownership.
- Team/workspace-shared AI billing is not part of v1.
- Local CLI executors require a separate remote-worker design before hosted use.
