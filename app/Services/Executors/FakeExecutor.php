<?php

namespace App\Services\Executors;

use App\Ai\Agents\SubtaskExecutor;
use App\Models\Repo;
use App\Models\Subtask;
use App\Services\Progress\ProgressEmitter;

/**
 * Test/no-op executor: invokes the agent (so SubtaskExecutor::fake() interception works)
 * without requiring a working directory. Used in feature tests where we only care about
 * orchestration, not the actual filesystem mutations.
 */
class FakeExecutor implements Executor
{
    public function needsWorkingDirectory(): bool
    {
        return false;
    }

    public function supportsProgressEvents(): bool
    {
        return false;
    }

    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch, ?string $contextBrief = null, ?ProgressEmitter $emitter = null, ?string $promptOverride = null): ExecutionResult
    {
        // Pass a real (but throwaway) directory so SubtaskExecutor::tools() can construct
        // a Sandbox during fake/test invocations. The tools are never actually called
        // because the prompt response is intercepted by SubtaskExecutor::fake().
        $agent = new SubtaskExecutor($subtask, $repo, $workingBranch, $workingDir ?? sys_get_temp_dir());
        if ($promptOverride !== null) {
            $prompt = $promptOverride;
            if ($contextBrief !== null && $contextBrief !== '') {
                $prompt = $contextBrief."\n\n".$prompt;
            }
        } else {
            $prompt = $agent->buildPrompt();
            if ($contextBrief !== null && $contextBrief !== '') {
                $prompt = $contextBrief."\n\n".$prompt;
            }
        }

        $response = $agent->prompt($prompt);
        $output = $response->toArray();

        return new ExecutionResult(
            summary: (string) ($output['summary'] ?? ''),
            filesChanged: array_values(array_map('strval', $output['files_changed'] ?? [])),
            commitMessage: (string) ($output['commit_message'] ?? ''),
        );
    }
}
