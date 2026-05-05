<?php

test('hosted deployment environment example teaches BYOK and locality knobs', function () {
    $env = readProjectFile('.env.example');

    expect($env)->toContain('SPECIFY_RUNTIME_ENV=local')
        ->and($env)->toContain('SPECIFY_REMOTE_EXECUTORS=')
        ->and($env)->toContain('SPECIFY_EXECUTOR_DRIVER=laravel-ai')
        ->and($env)->toContain('SPECIFY_EXECUTOR_RACE=')
        ->and($env)->toContain('MCP_USER_EMAIL=')
        ->and($env)->not->toContain('SPECIFY_ANTHROPIC_API_KEY=');
});

test('hosted deployment docs do not describe app-paid AI execution', function () {
    $readme = readProjectFile('README.md');
    $deployment = readProjectFile('docs/operations/hosted-deployment.md');
    $aiConfig = readProjectFile('config/ai.php');

    expect($readme)->toContain('users configure their own Anthropic/OpenAI key')
        ->and($readme)->toContain('SPECIFY_REMOTE_EXECUTORS')
        ->and($deployment)->toContain('Do not set an app-wide AI provider key')
        ->and($deployment)->toContain('operator assertion that the named driver is safe')
        ->and(readProjectFile('docs/architecture/agent-run-lifecycle.md'))->toContain('specify.runtime.remote_executors')
        ->and($aiConfig)->toContain('user-triggered agent calls through per-run BYOK provider')
        ->and($readme)->not->toContain('describe-only');
});

function readProjectFile(string $path): string
{
    $absolutePath = base_path($path);

    expect(is_readable($absolutePath), "{$path} should be readable")->toBeTrue();

    $contents = file_get_contents($absolutePath);

    expect($contents, "{$path} should be readable")->not->toBeFalse();

    return $contents;
}
