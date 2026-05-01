<?php

namespace App\Services;

/**
 * The terminal result of a SubtaskRunPipeline.
 *
 * `succeeded` and `pullRequestFailed` are both *successful* outcomes from the
 * AgentRun's perspective: the diff was committed and pushed; the only
 * difference is whether `git push` was followed by a clean PR open or a
 * non-fatal PR-creation error (ADR-0004 — PR opening must never fail the
 * run). Both carry the diff and route to `markSucceeded`. `noDiff` is the
 * only fatal outcome and routes to `markFailed`.
 *
 * Hard exceptions (executor crashes, git failures) propagate out of the
 * pipeline rather than being encoded here — the queue job catches those
 * separately so the queue can decide whether to retry.
 */
class SubtaskRunOutcome
{
    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_NO_DIFF = 'no_diff';

    public const STATE_PULL_REQUEST_FAILED = 'pull_request_failed';

    public const STATE_ALREADY_COMPLETE = 'already_complete';

    /**
     * @param  array<string, mixed>  $output
     */
    private function __construct(
        public string $state,
        public array $output,
        public ?string $diff,
        public ?string $error,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public static function succeeded(array $output, ?string $diff): self
    {
        return new self(self::STATE_SUCCEEDED, $output, $diff, null);
    }

    public static function noDiff(string $reason): self
    {
        return new self(self::STATE_NO_DIFF, [], null, $reason);
    }

    /**
     * The agent committed and pushed but the PR open failed. ADR-0004:
     * non-fatal — the run is still marked Succeeded; `output.pull_request_error`
     * carries the message for surface-level triage.
     *
     * @param  array<string, mixed>  $output
     */
    public static function pullRequestFailed(array $output, ?string $diff, string $error): self
    {
        return new self(self::STATE_PULL_REQUEST_FAILED, $output, $diff, $error);
    }

    /**
     * The Subtask's spec was already satisfied on the working branch — agent
     * produced no diff and cited commit SHAs the pipeline verified are
     * reachable from HEAD. ADR-0007: Succeeded-class outcome, cascade
     * advances. The agent's evidence and reason are stamped on `output` for
     * post-hoc audit.
     *
     * @param  array<string, mixed>  $output
     */
    public static function alreadyComplete(array $output): self
    {
        return new self(self::STATE_ALREADY_COMPLETE, $output, null, null);
    }

    /**
     * True for clean success, the non-fatal `pullRequestFailed`, and the
     * `alreadyComplete` no-op outcome. The job uses this to choose
     * markSucceeded vs markFailed; only `noDiff` returns false.
     */
    public function isSucceeded(): bool
    {
        return in_array(
            $this->state,
            [self::STATE_SUCCEEDED, self::STATE_PULL_REQUEST_FAILED, self::STATE_ALREADY_COMPLETE],
            true,
        );
    }
}
