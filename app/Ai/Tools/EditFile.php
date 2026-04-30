<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class EditFile implements Tool
{
    public function __construct(public Sandbox $sandbox) {}

    public function name(): string
    {
        return 'edit';
    }

    public function description(): Stringable|string
    {
        return 'Apply one or more exact-string replacements to a file. Each edit is matched against '
            .'the ORIGINAL file (not incrementally). `old_string` must match exactly once unless '
            .'`replace_all` is true. Do not include overlapping edits — merge them into one.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = (string) $request['path'];
        $edits = $request['edits'] ?? [];

        if (! is_array($edits) || $edits === []) {
            return 'Error: `edits` must be a non-empty array.';
        }

        try {
            $absolute = $this->sandbox->resolve($path);
        } catch (Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        if (! is_file($absolute) || ! is_readable($absolute)) {
            return "Error: not a readable file: {$path}";
        }

        $original = @file_get_contents($absolute);
        if ($original === false) {
            return "Error: unable to read {$path}";
        }

        $current = $original;
        $applied = 0;

        foreach ($edits as $i => $edit) {
            if (! is_array($edit) || ! array_key_exists('old_string', $edit) || ! array_key_exists('new_string', $edit)) {
                return "Error: edit #{$i} must have `old_string` and `new_string`.";
            }
            $old = (string) $edit['old_string'];
            $new = (string) $edit['new_string'];
            $replaceAll = (bool) ($edit['replace_all'] ?? false);

            if ($old === '') {
                return "Error: edit #{$i} `old_string` is empty.";
            }
            if ($old === $new) {
                return "Error: edit #{$i} `old_string` and `new_string` are identical.";
            }

            $count = substr_count($current, $old);
            if ($count === 0) {
                return "Error: edit #{$i} `old_string` not found.";
            }
            if ($count > 1 && ! $replaceAll) {
                return "Error: edit #{$i} `old_string` matches {$count} times; pass `replace_all: true` or add more context.";
            }

            $current = $replaceAll
                ? str_replace($old, $new, $current)
                : preg_replace('/'.preg_quote($old, '/').'/', $this->escapeReplacement($new), $current, 1);
            $applied++;
        }

        if ($current === $original) {
            return 'No changes (all edits were no-ops).';
        }

        if (@file_put_contents($absolute, $current) === false) {
            return "Error: unable to write {$path}";
        }

        return "Applied {$applied} edit(s) to ".$this->sandbox->relative($absolute).'.';
    }

    private function escapeReplacement(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '$' => '\\$']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('File path to edit.')->required(),
            'edits' => $schema->array()
                ->description('Each edit is an object with `old_string`, `new_string`, optional `replace_all`.')
                ->items($schema->object(fn ($s) => [
                    'old_string' => $s->string()->description('Exact text to match in the original file.')->required(),
                    'new_string' => $s->string()->description('Replacement text (may be empty).')->required(),
                    'replace_all' => $s->boolean()->description('Replace every occurrence (default false).'),
                ]))
                ->required(),
        ];
    }
}
