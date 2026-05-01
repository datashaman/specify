<?php

namespace App\Services\Reviews;

/**
 * One advisory comment to post on a PR review. Severity is metadata for
 * rendering; the host VCS treats every entry the same (a comment on a
 * COMMENT-style review).
 */
class ReviewComment
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_ERROR = 'error';

    public const SEVERITIES = [self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_ERROR];

    public function __construct(
        public string $body,
        public ?string $path = null,
        public ?int $line = null,
        public string $severity = self::SEVERITY_WARNING,
    ) {}

    /**
     * Whether this comment can be attached to a specific line on the diff.
     */
    public function isLineAttached(): bool
    {
        return $this->path !== null && $this->line !== null && $this->line > 0;
    }
}
