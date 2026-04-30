<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class WriteFile implements Tool
{
    public function __construct(public Sandbox $sandbox) {}

    public function description(): Stringable|string
    {
        return 'Write a file. Overwrites if it exists, creates parent dirs if missing. '
            .'Use `edit` for partial changes; this tool replaces the whole file.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = (string) $request['path'];
        $content = (string) $request['content'];

        try {
            $absolute = $this->sandbox->resolve($path, mustExist: false);
        } catch (Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        $dir = dirname($absolute);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return "Error: unable to create directory {$dir}";
        }

        $existed = is_file($absolute);
        if (@file_put_contents($absolute, $content) === false) {
            return "Error: unable to write {$path}";
        }

        $bytes = strlen($content);

        return ($existed ? 'Overwrote' : 'Created').' '.$this->sandbox->relative($absolute)." ({$bytes} bytes).";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('File path to write.')->required(),
            'content' => $schema->string()->description('New file contents.')->required(),
        ];
    }
}
