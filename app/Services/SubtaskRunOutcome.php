<?php

namespace App\Services;

/**
 * The terminal result of a SubtaskRunPipeline. Successful runs carry the
 * agent output plus diff; failure modes carry the message that should be
 * recorded on the AgentRun.
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
     * @param  array<string, mixed>  $output
     */
    public static function pullRequestFailed(array $output, string $error): self
    {
        return new self(self::STATE_PULL_REQUEST_FAILED, $output, null, $error);
    }

    /** True only for the clean Succeeded state — `noDiff` and `pullRequestFailed` return false. */
    public function isSucceeded(): bool
    {
        return $this->state === self::STATE_SUCCEEDED;
    }
}
