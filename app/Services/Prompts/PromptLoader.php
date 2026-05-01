<?php

namespace App\Services\Prompts;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads agent system prompts from `prompts/*.md` at the repo root.
 *
 * Lifting prompts into markdown files (instead of static heredocs in PHP
 * agent classes) lets them participate in code review, be searchable, and
 * reference ADRs without escape hell. The loader caches each file's contents
 * within a single PHP request so agents constructed in a tight loop don't
 * re-read disk.
 */
class PromptLoader
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(public string $basePath) {}

    /**
     * Load a prompt by short name, e.g. `load('subtask-executor')` reads
     * `prompts/subtask-executor.md`.
     *
     * @throws RuntimeException When the file does not exist.
     */
    public function load(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->basePath.DIRECTORY_SEPARATOR.$name.'.md';
        if (! File::exists($path)) {
            throw new RuntimeException("Prompt file not found: {$path}");
        }

        return $this->cache[$name] = trim(File::get($path));
    }
}
