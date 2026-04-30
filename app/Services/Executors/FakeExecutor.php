<?php

namespace App\Services\Executors;

use App\Ai\Agents\SubtaskExecutor;
use App\Models\Repo;
use App\Models\Subtask;

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

    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch): array
    {
        $agent = new SubtaskExecutor($subtask, $repo, $workingBranch);
        $response = $agent->prompt($agent->buildPrompt());
        $output = $response->toArray();

        return [
            'summary' => (string) ($output['summary'] ?? ''),
            'files_changed' => array_map('strval', $output['files_changed'] ?? []),
            'commit_message' => (string) ($output['commit_message'] ?? ''),
        ];
    }
}
