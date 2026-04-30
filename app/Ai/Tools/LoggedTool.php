<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Wraps a Tool to write structured log lines on every invocation. The log
 * shows what the agent asked for, how the tool responded, and how long it
 * took — enough to reconstruct what happened during a subtask run after the
 * fact, without changing the tool's behaviour.
 */
class LoggedTool implements Tool
{
    private const ARGS_PREVIEW = 400;

    private const RESULT_PREVIEW = 1_200;

    public function __construct(
        public readonly Tool $inner,
        public readonly array $context = [],
    ) {}

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $name = class_basename($this->inner);
        $start = microtime(true);

        Log::info('specify.tool.invoking', [
            ...$this->context,
            'tool' => $name,
            'args' => $this->preview($this->safeArgs($request), self::ARGS_PREVIEW),
        ]);

        try {
            $result = $this->inner->handle($request);
            $rendered = (string) $result;

            Log::info('specify.tool.invoked', [
                ...$this->context,
                'tool' => $name,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'result_bytes' => strlen($rendered),
                'result_preview' => $this->preview($rendered, self::RESULT_PREVIEW),
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::warning('specify.tool.failed', [
                ...$this->context,
                'tool' => $name,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Drop bulky fields like `content` so the log line stays readable.
     */
    private function safeArgs(Request $request): array
    {
        $args = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($value) && strlen($value) > 200) {
                $args[$key] = '<'.strlen($value).' bytes>';

                continue;
            }
            $args[$key] = $value;
        }

        return $args;
    }

    private function preview(mixed $value, int $max): string
    {
        $rendered = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($rendered === false) {
            $rendered = '<unencodable>';
        }
        if (strlen($rendered) <= $max) {
            return $rendered;
        }

        return substr($rendered, 0, $max).'… ['.(strlen($rendered) - $max).' more bytes]';
    }
}
