<?php

use App\Services\Executors\CliExecutor;
use App\Services\Executors\FakeExecutor;
use App\Services\Executors\LaravelAiExecutor;

return [
    /*
    |--------------------------------------------------------------------------
    | Runs path
    |--------------------------------------------------------------------------
    |
    | Per-AgentRun working directories are created here. Each run gets its
    | own subdirectory keyed by AgentRun id.
    |
    */

    'runs_path' => env('SPECIFY_RUNS_PATH', storage_path('app/runs')),

    /*
    |--------------------------------------------------------------------------
    | Git committer identity
    |--------------------------------------------------------------------------
    |
    | Used when WorkspaceRunner commits AI-generated changes.
    |
    */

    'git' => [
        'name' => env('SPECIFY_GIT_NAME', 'Specify Bot'),
        'email' => env('SPECIFY_GIT_EMAIL', 'bot@specify.local'),
    ],

    'workspace' => [
        'push_after_commit' => filter_var(env('SPECIFY_PUSH_AFTER_COMMIT', true), FILTER_VALIDATE_BOOLEAN),
        'open_pr_after_push' => filter_var(env('SPECIFY_OPEN_PR_AFTER_PUSH', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'github' => [
        'api_base' => env('SPECIFY_GITHUB_API_BASE', 'https://api.github.com'),
    ],

    'gitlab' => [
        'api_base' => env('SPECIFY_GITLAB_API_BASE', 'https://gitlab.com/api/v4'),
    ],

    'bitbucket' => [
        'api_base' => env('SPECIFY_BITBUCKET_API_BASE', 'https://api.bitbucket.org/2.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Executor
    |--------------------------------------------------------------------------
    |
    | Drives subtask execution. "laravel-ai" uses the laravel/ai SubtaskExecutor
    | agent and is describe-only (no filesystem mutation). "cli" spawns an
    | external agent CLI in the working directory and observes filesystem
    | changes via git afterward. Any CLI that supports one-shot prompting
    | works (claude, codex, gemini, aider, …) — supply binary + args.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | Local MCP servers (mcp:start) run without an HTTP request, so there is
    | no authenticated user. Set MCP_USER_EMAIL to the email of an existing
    | user to act as that user when serving local MCP tool calls. Web MCP
    | servers continue to use whatever auth middleware is on the route.
    |
    */

    'mcp' => [
        'user_email' => env('MCP_USER_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Executor drivers
    |--------------------------------------------------------------------------
    |
    | The `drivers` map registers every available executor by name. `default`
    | is the driver used when nothing else asks. `race`, when non-empty,
    | turns each Subtask dispatch into a *fan-out* — one AgentRun per
    | driver in the list, each on its own branch, each opening its own PR.
    | The reviewer picks one to merge; the choice is the data.
    |
    | Race driver names must exist in `drivers`. Single-driver mode
    | (race=[]) is the default and matches pre-race behaviour.
    |
    */

    'executor' => [
        'default' => env('SPECIFY_EXECUTOR_DRIVER', env('SPECIFY_EXECUTOR', 'laravel-ai')),

        'race' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SPECIFY_EXECUTOR_RACE', ''))
        ))),

        'drivers' => [
            'laravel-ai' => [
                'class' => LaravelAiExecutor::class,
            ],
            'cli' => [
                'class' => CliExecutor::class,
                'command' => array_values(array_filter(explode(' ', (string) env('SPECIFY_CLI_COMMAND', 'claude -p')))),
                'timeout' => (int) env('SPECIFY_CLI_TIMEOUT', 1800),
            ],
            'cli-claude' => [
                'class' => CliExecutor::class,
                'command' => ['claude', '-p'],
                'timeout' => (int) env('SPECIFY_CLI_TIMEOUT', 1800),
            ],
            'cli-codex' => [
                'class' => CliExecutor::class,
                'command' => ['codex', 'exec'],
                'timeout' => (int) env('SPECIFY_CLI_TIMEOUT', 1800),
            ],
            'fake' => [
                'class' => FakeExecutor::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context builder
    |--------------------------------------------------------------------------
    |
    | Per-subtask context brief prepended to the executor prompt. "recency"
    | (default) emits a small markdown block with files mentioned in the
    | Subtask description, recent commits touching them, and prior failed
    | runs on the same Subtask. "null" disables the brief entirely.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Advisory reviews
    |--------------------------------------------------------------------------
    |
    | After a PR opens, optionally post advisory reviews using one or more
    | personas. The only V1 persona is `adr-conformance` (flags PR diffs
    | that contradict an Accepted ADR). Reviews are always advisory — they
    | post as `COMMENT`-style reviews on the host VCS, never block merge.
    |
    */

    'review' => [
        'enabled' => filter_var(env('SPECIFY_REVIEW_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'personas' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('SPECIFY_REVIEW_PERSONAS', 'adr-conformance')))
        )),
    ],

    'context' => [
        'builder' => env('SPECIFY_CONTEXT_BUILDER', 'recency'),
        'recency' => [
            'window' => env('SPECIFY_CONTEXT_WINDOW', '30.days'),
            'max_files' => (int) env('SPECIFY_CONTEXT_MAX_FILES', 10),
        ],
    ],
];
