<?php

namespace App\Services\Executors;

use App\Ai\Agents\SubtaskExecutor;
use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Support\Facades\Log;

class LaravelAiExecutor implements Executor
{
    public function needsWorkingDirectory(): bool
    {
        return true;
    }

    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch): array
    {
        $context = [
            'subtask_id' => $subtask->getKey(),
            'story_id' => $subtask->task?->story_id,
            'branch' => $workingBranch,
            'working_dir' => $workingDir,
        ];

        Log::info('specify.subtask.agent.starting', $context + [
            'subtask_name' => $subtask->name,
        ]);
        $start = microtime(true);

        $agent = new SubtaskExecutor($subtask, $repo, $workingBranch, $workingDir);
        $response = $agent->prompt($agent->buildPrompt());
        $output = $response->toArray();

        Log::info('specify.subtask.agent.finished', $context + [
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            'summary' => (string) ($output['summary'] ?? ''),
            'files_changed' => array_map('strval', $output['files_changed'] ?? []),
            'commit_message' => (string) ($output['commit_message'] ?? ''),
        ]);

        return [
            'summary' => (string) ($output['summary'] ?? ''),
            'files_changed' => array_map('strval', $output['files_changed'] ?? []),
            'commit_message' => (string) ($output['commit_message'] ?? ''),
        ];
    }
}
