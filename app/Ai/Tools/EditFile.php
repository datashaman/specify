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

    public function description(): Stringable|string
    {
        return 'Apply one or more exact-string replacements to a file. Each edit is matched against '
            .'the ORIGINAL file (not incrementally), so two edits cannot rely on each other. '
            .'`old_string` must match exactly once unless `replace_all` is true. Overlapping edits '
            .'are rejected — merge them into one.';
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

        // Phase 1: validate each edit and collect every replacement [start,end,new]
        // tuple by scanning the ORIGINAL string. No edit can see another edit's effect.
        $replacements = [];

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

            $offsets = $this->findAll($original, $old);
            if ($offsets === []) {
                return "Error: edit #{$i} `old_string` not found.";
            }
            if (count($offsets) > 1 && ! $replaceAll) {
                return "Error: edit #{$i} `old_string` matches ".count($offsets)
                    .' times; pass `replace_all: true` or add more context.';
            }

            $oldLen = strlen($old);
            foreach ($offsets as $offset) {
                $replacements[] = [
                    'edit' => $i,
                    'start' => $offset,
                    'end' => $offset + $oldLen,
                    'new' => $new,
                ];
            }
        }

        // Phase 2: detect overlap between any two replacement spans.
        usort($replacements, fn ($a, $b) => $a['start'] <=> $b['start']);
        for ($i = 1, $n = count($replacements); $i < $n; $i++) {
            if ($replacements[$i]['start'] < $replacements[$i - 1]['end']) {
                return "Error: edit #{$replacements[$i]['edit']} overlaps with edit #{$replacements[$i - 1]['edit']}; merge them.";
            }
        }

        // Phase 3: apply in reverse so earlier offsets stay valid as the string grows/shrinks.
        $current = $original;
        for ($i = count($replacements) - 1; $i >= 0; $i--) {
            $r = $replacements[$i];
            $current = substr($current, 0, $r['start']).$r['new'].substr($current, $r['end']);
        }

        if ($current === $original) {
            return 'No changes (all edits were no-ops).';
        }

        if (@file_put_contents($absolute, $current) === false) {
            return "Error: unable to write {$path}";
        }

        return 'Applied '.count($edits).' edit(s) to '.$this->sandbox->relative($absolute).'.';
    }

    /**
     * @return array<int, int>
     */
    private function findAll(string $haystack, string $needle): array
    {
        $offsets = [];
        $pos = 0;
        while (($pos = strpos($haystack, $needle, $pos)) !== false) {
            $offsets[] = $pos;
            $pos += strlen($needle);
        }

        return $offsets;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('File path to edit.')->required(),
            'edits' => $schema->array()
                ->description('Each edit is an object with `old_string`, `new_string`, optional `replace_all`. All edits are matched against the original file content.')
                ->items($schema->object(fn ($s) => [
                    'old_string' => $s->string()->description('Exact text to match in the original file.')->required(),
                    'new_string' => $s->string()->description('Replacement text (may be empty).')->required(),
                    'replace_all' => $s->boolean()->description('Replace every occurrence (default false).'),
                ]))
                ->required(),
        ];
    }
}
