<?php

namespace App\Services\Executors;

/**
 * Typed return of Executor::execute. Replaces the array shape
 * {summary, files_changed, commit_message} that the Executor seam used to
 * leak — callers now have a real value object to consume.
 */
class ExecutionResult
{
    /**
     * @param  list<string>  $filesChanged
     * @param  string|null  $executorLog  Optional full executor transcript (CLI stdout+stderr,
     *                                    or richer trace from in-process executors). Persisted
     *                                    verbatim on AgentRun.output for debugging; never used
     *                                    by the pipeline for control flow.
     */
    public function __construct(
        public string $summary,
        public array $filesChanged,
        public string $commitMessage,
        public ?string $executorLog = null,
    ) {}

    /**
     * Serialise to the JSON-friendly shape stored on AgentRun.output.
     *
     * @return array{summary: string, files_changed: list<string>, commit_message: string, executor_log?: string}
     */
    public function toArray(): array
    {
        $out = [
            'summary' => $this->summary,
            'files_changed' => $this->filesChanged,
            'commit_message' => $this->commitMessage,
        ];

        if ($this->executorLog !== null && $this->executorLog !== '') {
            $out['executor_log'] = $this->executorLog;
        }

        return $out;
    }
}
