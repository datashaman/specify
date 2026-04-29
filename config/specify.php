<?php

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
    | Drives task execution. "laravel-ai" uses the laravel/ai TaskExecutor
    | agent and is describe-only (no filesystem mutation). "cli" spawns an
    | external agent CLI in the working directory and observes filesystem
    | changes via git afterward. Any CLI that supports one-shot prompting
    | works (claude, codex, gemini, aider, …) — supply binary + args.
    |
    */

    'executor' => [
        'driver' => env('SPECIFY_EXECUTOR', 'laravel-ai'),

        'cli' => [
            'command' => array_values(array_filter(explode(' ', (string) env('SPECIFY_CLI_COMMAND', 'claude -p')))),
            'timeout' => (int) env('SPECIFY_CLI_TIMEOUT', 1800),
        ],
    ],
];
