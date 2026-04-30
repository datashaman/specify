<?php

namespace App\Services\Executors;

use App\Ai\Agents\SubtaskExecutor;
use App\Models\Repo;
use App\Models\Subtask;

class LaravelAiExecutor implements Executor
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
