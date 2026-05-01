<?php

namespace App\Services\Executors;

use App\Ai\Agents\SubtaskExecutor;
use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Executor backed by the in-tree `SubtaskExecutor` agent (laravel/ai).
 *
 * Drives the Anthropic-backed agent to read and edit files in a checked-out
 * working directory via tool calls. API errors and "no structured output"
 * responses surface as their own RuntimeExceptions so the pipeline can
 * report them distinctly (see commit 29f524c).
 */
class LaravelAiExecutor implements Executor
{
    public function needsWorkingDirectory(): bool
    {
        return true;
    }

    /**
     * Run the agent loop and translate its final output into an `ExecutionResult`.
     *
     * @throws RuntimeException When the AI provider returns an HTTP error,
     *                          or when the agent terminates without producing
     *                          summary/files/commit-message fields.
     */
    public function execute(Subtask $subtask, ?string $workingDir, ?Repo $repo, ?string $workingBranch): ExecutionResult
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

        try {
            $response = $agent->prompt($agent->buildPrompt());
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $body = $e->response?->body();
            $snippet = $body ? substr($body, 0, 500) : '';
            Log::error('specify.subtask.agent.api_error', $context + [
                'status' => $status,
                'body' => $snippet,
            ]);
            throw new RuntimeException(
                'AI provider returned HTTP '.($status ?? '?').
                ($status === 429 ? ' (rate limited / monthly cap reached).' : '.').
                ($snippet !== '' ? ' Body: '.$snippet : ''),
                previous: $e,
            );
        } catch (Throwable $e) {
            Log::error('specify.subtask.agent.exception', $context + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $output = $response->toArray();
        $summary = trim((string) ($output['summary'] ?? ''));
        $files = array_values(array_filter(array_map('strval', $output['files_changed'] ?? [])));
        $commitMessage = trim((string) ($output['commit_message'] ?? ''));

        Log::info('specify.subtask.agent.finished', $context + [
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            'summary' => $summary,
            'files_changed' => $files,
            'commit_message' => $commitMessage,
        ]);

        if ($summary === '' && $files === [] && $commitMessage === '') {
            throw new RuntimeException(
                'Agent returned without producing any structured output. '
                .'Likely causes: the model never called the tools (check the prompt), '
                .'MaxSteps was exhausted, or the response was truncated.'
            );
        }

        return new ExecutionResult($summary, $files, $commitMessage);
    }
}
