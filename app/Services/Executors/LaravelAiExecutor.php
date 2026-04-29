<?php

namespace App\Services\Executors;

use App\Ai\Agents\TaskExecutor;
use App\Models\Repo;
use App\Models\Task;

class LaravelAiExecutor implements Executor
{
    public function needsWorkingDirectory(): bool
    {
        return false;
    }

    public function execute(Task $task, ?string $workingDir, ?Repo $repo, ?string $workingBranch): array
    {
        $agent = new TaskExecutor($task, $repo, $workingBranch);
        $response = $agent->prompt($agent->buildPrompt());
        $output = $response->toArray();

        return [
            'summary' => (string) ($output['summary'] ?? ''),
            'files_changed' => array_map('strval', $output['files_changed'] ?? []),
            'commit_message' => (string) ($output['commit_message'] ?? ''),
        ];
    }
}
