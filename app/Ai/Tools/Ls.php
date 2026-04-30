<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class Ls implements Tool
{
    private const DEFAULT_LIMIT = 500;

    private const MAX_LIMIT = 5_000;

    public function __construct(public Sandbox $sandbox) {}

    public function name(): string
    {
        return 'ls';
    }

    public function description(): Stringable|string
    {
        return 'List entries in a directory. Marks directories with a trailing slash.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $dir = isset($request['path'])
                ? $this->sandbox->resolve((string) $request['path'])
                : $this->sandbox->root;
        } catch (Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        if (! is_dir($dir)) {
            return 'Error: not a directory: '.$this->sandbox->relative($dir);
        }

        $limit = min(self::MAX_LIMIT, max(1, (int) ($request['limit'] ?? self::DEFAULT_LIMIT)));

        $entries = @scandir($dir);
        if ($entries === false) {
            return 'Error: unable to read directory.';
        }
        $entries = array_values(array_filter($entries, fn (string $e) => $e !== '.' && $e !== '..'));

        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        $total = count($entries);
        $shown = array_slice($entries, 0, $limit);
        $rendered = [];
        foreach ($shown as $name) {
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            $rendered[] = is_dir($full) ? $name.'/' : $name;
        }

        $note = $total > $limit ? "\n[truncated: showing {$limit} of {$total}]" : '';

        return implode("\n", $rendered).$note;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Directory to list (default: working dir).'),
            'limit' => $schema->integer()->description('Max entries (default '.self::DEFAULT_LIMIT.', max '.self::MAX_LIMIT.').'),
        ];
    }
}
