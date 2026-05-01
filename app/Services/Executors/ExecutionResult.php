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
     */
    public function __construct(
        public string $summary,
        public array $filesChanged,
        public string $commitMessage,
    ) {}

    /**
     * Serialise to the JSON-friendly shape stored on AgentRun.output.
     *
     * @return array{summary: string, files_changed: list<string>, commit_message: string}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'files_changed' => $this->filesChanged,
            'commit_message' => $this->commitMessage,
        ];
    }
}
