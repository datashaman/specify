<?php

test('hosted deployment environment example teaches BYOK and locality knobs', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('SPECIFY_RUNTIME_ENV=local')
        ->and($env)->toContain('SPECIFY_EXECUTOR_DRIVER=laravel-ai')
        ->and($env)->toContain('SPECIFY_EXECUTOR_RACE=')
        ->and($env)->toContain('MCP_USER_EMAIL=')
        ->and($env)->not->toContain('SPECIFY_ANTHROPIC_API_KEY=');
});

test('hosted deployment docs do not describe app-paid AI execution', function () {
    $readme = file_get_contents(base_path('README.md'));
    $deployment = file_get_contents(base_path('docs/operations/hosted-deployment.md'));
    $aiConfig = file_get_contents(base_path('config/ai.php'));

    expect($readme)->toContain('users configure their own Anthropic/OpenAI key')
        ->and($deployment)->toContain('Do not set an app-wide AI provider key')
        ->and($aiConfig)->toContain('user-triggered agent calls through per-run BYOK provider')
        ->and($readme)->not->toContain('describe-only');
});
