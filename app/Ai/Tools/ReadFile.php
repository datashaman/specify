<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class ReadFile implements Tool
{
    private const MAX_BYTES = 256_000;

    private const MAX_LINES = 2_000;

    public function __construct(public Sandbox $sandbox) {}

    public function name(): string
    {
        return 'read';
    }

    public function description(): Stringable|string
    {
        return 'Read a file from the working tree. Optional offset (1-based line) and limit (line count). '
            .'Output is truncated to '.self::MAX_LINES.' lines or '.self::MAX_BYTES.' bytes.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = (string) $request['path'];
        $offset = isset($request['offset']) ? max(1, (int) $request['offset']) : 1;
        $limit = isset($request['limit']) ? max(1, (int) $request['limit']) : self::MAX_LINES;

        try {
            $absolute = $this->sandbox->resolve($path);
        } catch (Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        if (! is_file($absolute) || ! is_readable($absolute)) {
            return "Error: not a readable file: {$path}";
        }

        $contents = @file_get_contents($absolute, length: self::MAX_BYTES);
        if ($contents === false) {
            return "Error: unable to read {$path}";
        }

        $allLines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $sliced = array_slice($allLines, $offset - 1, $limit);
        $rendered = [];
        foreach ($sliced as $i => $line) {
            $rendered[] = sprintf('%6d→%s', $offset + $i, $line);
        }

        $truncated = '';
        if (filesize($absolute) > self::MAX_BYTES) {
            $truncated = "\n[truncated: file exceeds ".self::MAX_BYTES.' bytes]';
        } elseif (count($allLines) > $offset - 1 + count($sliced)) {
            $truncated = "\n[truncated: showing lines {$offset}-".($offset + count($sliced) - 1).' of '.count($allLines).']';
        }

        return implode("\n", $rendered).$truncated;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('File path (relative to repo root or absolute inside it).')->required(),
            'offset' => $schema->integer()->description('1-based line to start at.'),
            'limit' => $schema->integer()->description('Max lines to return.'),
        ];
    }
}
