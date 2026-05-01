<?php

namespace App\Services\Executors;

/**
 * Typed return of Executor::execute. Replaces the array shape
 * {summary, files_changed, commit_message} that the Executor seam used to
 * leak — callers now have a real value object to consume.
 *
 * `clarifications` and `proposedSubtasks` are the executor's voice channels
 * (ADR-0005). Defaults are empty so existing tests and CLI-driver runs that
 * cannot emit structured output remain valid.
 */
class ExecutionResult
{
    /**
     * @param  list<string>  $filesChanged
     * @param  string|null  $executorLog  Optional full executor transcript.
     * @param  list<ExecutorClarification>  $clarifications  Voice channel for ambiguity / conflict / etc.
     * @param  list<ProposedSubtask>  $proposedSubtasks  Append-only follow-up Subtasks the
     *                                                   executor judges necessary; pipeline
     *                                                   attaches them to the same parent Task.
     */
    public function __construct(
        public string $summary,
        public array $filesChanged,
        public string $commitMessage,
        public ?string $executorLog = null,
        public array $clarifications = [],
        public array $proposedSubtasks = [],
    ) {}

    /**
     * Serialise to the JSON-friendly shape stored on AgentRun.output.
     *
     * @return array<string, mixed>
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

        if ($this->clarifications !== []) {
            $out['clarifications'] = array_map(fn (ExecutorClarification $c) => $c->toArray(), $this->clarifications);
        }

        if ($this->proposedSubtasks !== []) {
            $out['proposed_subtasks'] = array_map(fn (ProposedSubtask $p) => $p->toArray(), $this->proposedSubtasks);
        }

        return $out;
    }
}
