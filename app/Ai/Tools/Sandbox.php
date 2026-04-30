<?php

namespace App\Ai\Tools;

use RuntimeException;

class Sandbox
{
    public readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("Sandbox root not found: {$root}");
        }
        $this->root = $real;
    }

    /**
     * Resolve a user-supplied path against the sandbox root and verify it stays inside.
     * For paths that may not exist yet (write/edit creating new files), pass $mustExist=false.
     */
    public function resolve(string $path, bool $mustExist = true): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Empty path.');
        }

        $candidate = $this->isAbsolute($path) ? $path : $this->root.DIRECTORY_SEPARATOR.$path;
        $candidate = $this->normalize($candidate);

        if ($mustExist) {
            $real = realpath($candidate);
            if ($real === false) {
                throw new RuntimeException("Path not found: {$path}");
            }
            $candidate = $real;
        } else {
            $parent = dirname($candidate);
            $realParent = realpath($parent);
            if ($realParent === false) {
                throw new RuntimeException("Parent directory not found: {$parent}");
            }
            $candidate = $realParent.DIRECTORY_SEPARATOR.basename($candidate);
        }

        if ($candidate !== $this->root && ! str_starts_with($candidate, $this->root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Path escapes sandbox: {$path}");
        }

        return $candidate;
    }

    public function relative(string $absolute): string
    {
        if ($absolute === $this->root) {
            return '.';
        }

        $prefix = $this->root.DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (preg_split('#[\\\\/]+#', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return (str_starts_with($path, '/') ? '/' : '').implode(DIRECTORY_SEPARATOR, $parts);
    }
}
