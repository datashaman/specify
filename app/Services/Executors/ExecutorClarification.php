<?php

namespace App\Services\Executors;

/**
 * Voice channel for the executor (ADR-0005). A Subtask run can succeed AND
 * report ambiguity, conflict, missing context, or disagreement that the
 * human reviewer should see alongside the diff.
 */
class ExecutorClarification
{
    public const KIND_AMBIGUITY = 'ambiguity';

    public const KIND_CONFLICT = 'conflict';

    public const KIND_MISSING_CONTEXT = 'missing-context';

    public const KIND_DISAGREEMENT = 'disagreement';

    public const KINDS = [
        self::KIND_AMBIGUITY,
        self::KIND_CONFLICT,
        self::KIND_MISSING_CONTEXT,
        self::KIND_DISAGREEMENT,
    ];

    public function __construct(
        public string $kind,
        public string $message,
        public ?string $proposed = null,
    ) {}

    /**
     * Hydrate from the executor's structured output, defensively.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $kind = (string) ($payload['kind'] ?? '');
        $message = trim((string) ($payload['message'] ?? ''));
        if (! in_array($kind, self::KINDS, true) || $message === '') {
            return null;
        }
        $proposed = isset($payload['proposed']) ? trim((string) $payload['proposed']) : null;

        return new self($kind, $message, $proposed === '' ? null : $proposed);
    }

    /**
     * @return array{kind: string, message: string, proposed?: string}
     */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind, 'message' => $this->message];
        if ($this->proposed !== null) {
            $out['proposed'] = $this->proposed;
        }

        return $out;
    }
}
