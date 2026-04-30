<?php

namespace App\Ai\Tools;

use FilesystemIterator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Stringable;
use Throwable;

class Find implements Tool
{
    private const DEFAULT_LIMIT = 1000;

    private const MAX_LIMIT = 10_000;

    private const SKIP_DIRS = ['.git', 'node_modules', 'vendor', '.idea', '.vscode'];

    public function __construct(public Sandbox $sandbox) {}

    public function name(): string
    {
        return 'find';
    }

    public function description(): Stringable|string
    {
        return 'Find files matching a glob pattern. Skips .git, node_modules, vendor, .idea, .vscode by default.';
    }

    public function handle(Request $request): Stringable|string
    {
        $pattern = (string) ($request['pattern'] ?? '');
        if ($pattern === '') {
            return 'Error: empty pattern.';
        }

        try {
            $base = isset($request['path'])
                ? $this->sandbox->resolve((string) $request['path'])
                : $this->sandbox->root;
        } catch (Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        if (! is_dir($base)) {
            return 'Error: not a directory: '.$this->sandbox->relative($base);
        }

        $limit = min(self::MAX_LIMIT, max(1, (int) ($request['limit'] ?? self::DEFAULT_LIMIT)));

        $directory = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $file) {
            return ! ($file->isDir() && in_array($file->getFilename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($filtered);

        $matches = [];
        $total = 0;
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = $this->sandbox->relative($file->getPathname());
            if (! $this->matches($relative, $pattern)) {
                continue;
            }
            $total++;
            if (count($matches) < $limit) {
                $matches[] = $relative;
            }
        }

        if ($matches === []) {
            return 'No matches.';
        }

        sort($matches, SORT_NATURAL);
        $note = $total > $limit ? "\n[truncated: showing {$limit} of {$total}]" : '';

        return implode("\n", $matches).$note;
    }

    private function matches(string $relative, string $pattern): bool
    {
        if (fnmatch($pattern, $relative, FNM_NOESCAPE)) {
            return true;
        }
        if (fnmatch($pattern, basename($relative), FNM_NOESCAPE)) {
            return true;
        }
        if (str_contains($pattern, '**')) {
            $regex = '#^'.str_replace(['\\*\\*/', '\\*\\*', '\\*', '\\?'], ['(?:.+/)?', '.*', '[^/]*', '.'], preg_quote($pattern, '#')).'$#';

            return preg_match($regex, $relative) === 1;
        }

        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pattern' => $schema->string()->description("Glob, e.g. '*.php', '**/*.blade.php', 'app/**/Service.php'.")->required(),
            'path' => $schema->string()->description('Directory to search (default: working dir).'),
            'limit' => $schema->integer()->description('Max results (default '.self::DEFAULT_LIMIT.', max '.self::MAX_LIMIT.').'),
        ];
    }
}
