<?php

namespace App\Services\Executors;

/**
 * Executor-proposed follow-up Subtask (ADR-0005). Always appended to the
 * parent Task of the Subtask that produced it; the pipeline rejects entries
 * that try to attach elsewhere.
 */
class ProposedSubtask
{
    public function __construct(
        public string $name,
        public string $description,
        public string $reason,
    ) {}

    /**
     * Hydrate from the executor's structured output, defensively.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($name === '' || $description === '' || $reason === '') {
            return null;
        }

        return new self($name, $description, $reason);
    }

    /**
     * @return array{name: string, description: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'reason' => $this->reason,
        ];
    }
}
