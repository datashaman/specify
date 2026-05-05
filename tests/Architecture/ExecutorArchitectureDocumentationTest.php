<?php

test('current executor guidance points at the current ADR', function () {
    $agents = readExecutorDoc('AGENTS.md');
    $lifecycle = readExecutorDoc('docs/architecture/agent-run-lifecycle.md');
    $executor = readExecutorDoc('app/Services/Executors/Executor.php');
    $adrIndex = readExecutorDoc('docs/adr/README.md');

    expect($agents)->toContain('ADR-0014')
        ->and($lifecycle)->toContain('ADR-0014')
        ->and($executor)->toContain('ADR-0014')
        ->and($adrIndex)->toContain('Superseded by [0014]');
});

test('current executor ADR describes the implemented drivers', function () {
    $oldAdr = readExecutorDoc('docs/adr/0003-pluggable-executor-interface.md');
    $currentAdr = readExecutorDoc('docs/adr/0014-executor-contract-and-runtime-locality.md');

    expect($oldAdr)->toContain('Status: Superseded by [0014]')
        ->and($currentAdr)->toContain('LaravelAiExecutor')
        ->and($currentAdr)->toContain('SubtaskExecutor')
        ->and($currentAdr)->toContain('BYOK')
        ->and($currentAdr)->toContain('specify.runtime.remote_executors')
        ->and($currentAdr)->not->toContain('TaskExecutor');
});

function readExecutorDoc(string $path): string
{
    $absolutePath = base_path($path);

    expect(is_readable($absolutePath), "{$path} should be readable")->toBeTrue();

    $contents = file_get_contents($absolutePath);

    expect($contents, "{$path} should be readable")->not->toBeFalse();

    return $contents;
}
