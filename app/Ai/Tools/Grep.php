<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class Grep implements Tool
{
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 1000;

    public function __construct(public Sandbox $sandbox) {}

    public function description(): Stringable|string
    {
        return 'Search file contents (uses ripgrep when available, falls back to grep -r). '
            .'Returns up to '.self::DEFAULT_LIMIT.' matches by default.';
    }

    public function handle(Request $request): Stringable|string
    {
        $pattern = (string) ($request['pattern'] ?? '');
        if ($pattern === '') {
            return 'Error: empty pattern.';
        }

        try {
            $searchRoot = isset($request['path'])
                ? $this->sandbox->resolve((string) $request['path'])
                : $this->sandbox->root;
        } catch (Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        $glob = isset($request['glob']) ? (string) $request['glob'] : null;
        $ignoreCase = (bool) ($request['ignore_case'] ?? false);
        $literal = (bool) ($request['literal'] ?? false);
        $context = max(0, (int) ($request['context'] ?? 0));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($request['limit'] ?? self::DEFAULT_LIMIT)));

        $finder = new ExecutableFinder;
        $rg = $finder->find('rg');

        $command = $rg !== null
            ? $this->buildRipgrepCommand($rg, $pattern, $searchRoot, $glob, $ignoreCase, $literal, $context, $limit)
            : $this->buildGrepCommand($pattern, $searchRoot, $glob, $ignoreCase, $literal, $context, $limit);

        $process = new Process($command, $this->sandbox->root);
        $process->setTimeout(60);
        $process->run();

        $stdout = trim($process->getOutput());
        if ($stdout === '') {
            return 'No matches.';
        }

        $lines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
        $count = count($lines);
        $shown = array_slice($lines, 0, $limit);
        $note = $count > $limit ? "\n[truncated: showing {$limit} of {$count}+ lines]" : '';

        return implode("\n", $shown).$note;
    }

    /** @return array<int, string> */
    private function buildRipgrepCommand(string $rg, string $pattern, string $root, ?string $glob, bool $ignoreCase, bool $literal, int $context, int $limit): array
    {
        $args = [$rg, '--line-number', '--no-heading', '--color=never'];
        if ($literal) {
            $args[] = '--fixed-strings';
        }
        if ($ignoreCase) {
            $args[] = '-i';
        }
        if ($context > 0) {
            $args[] = '-C';
            $args[] = (string) $context;
        }
        if ($glob !== null) {
            $args[] = '-g';
            $args[] = $glob;
        }
        $args[] = '--max-count';
        $args[] = (string) $limit;
        $args[] = $pattern;
        $args[] = $root;

        return $args;
    }

    /** @return array<int, string> */
    private function buildGrepCommand(string $pattern, string $root, ?string $glob, bool $ignoreCase, bool $literal, int $context, int $limit): array
    {
        $args = ['grep', '-rnI', '--color=never'];
        if ($literal) {
            $args[] = '-F';
        }
        if ($ignoreCase) {
            $args[] = '-i';
        }
        if ($context > 0) {
            $args[] = '-C';
            $args[] = (string) $context;
        }
        if ($glob !== null) {
            $args[] = '--include';
            $args[] = $glob;
        }
        $args[] = '-m';
        $args[] = (string) $limit;
        $args[] = '-e';
        $args[] = $pattern;
        $args[] = $root;

        return $args;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pattern' => $schema->string()->description('Regex pattern (or literal if `literal` is true).')->required(),
            'path' => $schema->string()->description('File or directory to search (default: working dir).'),
            'glob' => $schema->string()->description("Glob filter, e.g. '*.php' or '**/*.blade.php'."),
            'ignore_case' => $schema->boolean()->description('Case-insensitive (default false).'),
            'literal' => $schema->boolean()->description('Treat pattern as literal string (default false).'),
            'context' => $schema->integer()->description('Lines of context around each match (default 0).'),
            'limit' => $schema->integer()->description('Max matches (default '.self::DEFAULT_LIMIT.', max '.self::MAX_LIMIT.').'),
        ];
    }
}
