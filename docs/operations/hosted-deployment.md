# Hosted deployment

Specify can be deployed as a normal Laravel app, but AI execution has two
operator-facing constraints:

- users fund AI calls with their own BYOK credentials
- local CLI executors must not run on the hosted server by accident

## Runtime mode

Set hosted runtime explicitly:

```dotenv
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=database
SPECIFY_RUNTIME_ENV=hosted
SPECIFY_REMOTE_EXECUTORS=
SPECIFY_EXECUTOR_DRIVER=laravel-ai
SPECIFY_EXECUTOR_RACE=
```

By default, `SPECIFY_RUNTIME_ENV=hosted` makes `ExecutorFactory` reject
local-only drivers such as `cli`, `cli-claude`, `cli-codex`, and `fake`.
Hosted execution should use `laravel-ai` unless a separate remote-worker
deployment has been designed and the driver is explicitly listed in
`SPECIFY_REMOTE_EXECUTORS`.

If a hosted deployment intentionally runs a CLI driver on an isolated remote
worker with its own credentials and binaries, name that driver explicitly:

```dotenv
SPECIFY_REMOTE_EXECUTORS=cli-codex
SPECIFY_EXECUTOR_DRIVER=cli-codex
```

Multiple explicitly remote drivers can be comma-separated. This is an
operator assertion that the named driver is safe in this deployment; it does
not install binaries, provision credentials, or sandbox the worker.

## BYOK

Users configure Anthropic or OpenAI credentials in `/settings/ai`. The key is
encrypted in `user_ai_credentials`, and each `AgentRun` stores the `user_id`
that owns and funds the run.

Do not set an app-wide AI provider key for user-triggered execution. BYOK
resolution builds a per-run Laravel AI provider config from the run owner's
enabled credential and removes that temporary provider after the call.

## Queue workers

Run a real queue worker in production. The default `.env.example` uses the
database queue because it is portable:

```bash
php artisan queue:work --tries=1 --timeout=3600
```

Long-running workers should be restarted during deploys so config, prompts,
and code changes are picked up:

```bash
php artisan queue:restart
```

## Git and pull requests

Configure the bot identity and PR behavior:

```dotenv
SPECIFY_GIT_NAME="Specify Bot"
SPECIFY_GIT_EMAIL=bot@example.com
SPECIFY_PUSH_AFTER_COMMIT=true
SPECIFY_OPEN_PR_AFTER_PUSH=true
```

Attach repos through the app so repository access tokens and webhook secrets
are encrypted per `Repo`. PR creation is intentionally non-fatal; failures are
recorded on the `AgentRun` as `pull_request_error`.

## MCP stdio servers

Local MCP stdio servers do not have a browser session. Set `MCP_USER_EMAIL` to
an existing user when running `mcp:start` locally:

```dotenv
MCP_USER_EMAIL=operator@example.com
```

Web-authenticated app routes continue to use the Laravel session user.
