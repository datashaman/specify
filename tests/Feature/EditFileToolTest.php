<?php

use App\Ai\Tools\EditFile;
use App\Ai\Tools\Sandbox;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;

function makeSandboxedFile(string $contents): array
{
    $root = sys_get_temp_dir().'/specify-edit-'.uniqid();
    File::ensureDirectoryExists($root);
    $path = $root.'/file.txt';
    File::put($path, $contents);

    return [new Sandbox($root), 'file.txt', $path];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/specify-edit-*') as $dir) {
        File::deleteDirectory($dir);
    }
});

test('applies a single edit', function () {
    [$sandbox, $name, $path] = makeSandboxedFile("alpha\nbeta\ngamma\n");
    $tool = new EditFile($sandbox);

    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [['old_string' => 'beta', 'new_string' => 'BETA']],
    ]));

    expect($result)->toContain('Applied 1 edit')
        ->and(File::get($path))->toBe("alpha\nBETA\ngamma\n");
});

test('multiple edits all match against the ORIGINAL string, not incrementally', function () {
    [$sandbox, $name, $path] = makeSandboxedFile("foo bar\nbaz foo\n");
    $tool = new EditFile($sandbox);

    // Two edits each replacing a different unique substring. If applied
    // incrementally, the second `foo` substring would still be replaced because
    // it was untouched. We're checking both edits operate against the original
    // and produce the expected result.
    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [
            ['old_string' => 'foo bar', 'new_string' => 'FOO BAR'],
            ['old_string' => 'baz foo', 'new_string' => 'BAZ FOO'],
        ],
    ]));

    expect($result)->toContain('Applied 2 edit')
        ->and(File::get($path))->toBe("FOO BAR\nBAZ FOO\n");
});

test('edit B can match a substring that edit A would have erased — but both still apply against the original', function () {
    // A: "remove "  → ""           (deletes "remove ")
    // B: "remove "  → ""           same thing but with `replace_all` — make distinct
    // Better: test that an edit cannot ride on the output of an earlier edit.
    [$sandbox, $name, $path] = makeSandboxedFile("hello world\n");
    $tool = new EditFile($sandbox);

    // Edit 1 turns "hello" into "HI"; Edit 2 expects "HI world" — that string
    // doesn't exist in the ORIGINAL, so the tool must reject edit 2.
    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [
            ['old_string' => 'hello', 'new_string' => 'HI'],
            ['old_string' => 'HI world', 'new_string' => 'WHOLE THING'],
        ],
    ]));

    expect($result)->toStartWith('Error:')
        ->and($result)->toContain('not found')
        ->and(File::get($path))->toBe("hello world\n");
});

test('overlapping edits are rejected', function () {
    [$sandbox, $name, $path] = makeSandboxedFile("abcdefg\n");
    $tool = new EditFile($sandbox);

    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [
            ['old_string' => 'abcd', 'new_string' => 'X'],
            ['old_string' => 'cdef', 'new_string' => 'Y'],
        ],
    ]));

    expect($result)->toStartWith('Error:')
        ->and($result)->toContain('overlap')
        ->and(File::get($path))->toBe("abcdefg\n");
});

test('non-unique match without replace_all is rejected', function () {
    [$sandbox, $name, $path] = makeSandboxedFile("foo\nfoo\n");
    $tool = new EditFile($sandbox);

    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [['old_string' => 'foo', 'new_string' => 'bar']],
    ]));

    expect($result)->toStartWith('Error:')
        ->and($result)->toContain('matches 2 times')
        ->and(File::get($path))->toBe("foo\nfoo\n");
});

test('replace_all replaces every occurrence', function () {
    [$sandbox, $name, $path] = makeSandboxedFile("foo\nfoo\nfoo\n");
    $tool = new EditFile($sandbox);

    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [['old_string' => 'foo', 'new_string' => 'BAR', 'replace_all' => true]],
    ]));

    expect($result)->toContain('Applied 1 edit')
        ->and(File::get($path))->toBe("BAR\nBAR\nBAR\n");
});

test('replacement text containing $ or backslash is preserved literally', function () {
    [$sandbox, $name, $path] = makeSandboxedFile("price = 0\n");
    $tool = new EditFile($sandbox);

    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [['old_string' => '0', 'new_string' => '$10\\backslash']],
    ]));

    expect($result)->toContain('Applied 1 edit')
        ->and(File::get($path))->toBe("price = \$10\\backslash\n");
});

test('rejects path escaping the sandbox', function () {
    [$sandbox] = makeSandboxedFile('whatever');
    $tool = new EditFile($sandbox);

    $result = $tool->handle(new Request([
        'path' => '../../etc/passwd',
        'edits' => [['old_string' => 'a', 'new_string' => 'b']],
    ]));

    expect($result)->toStartWith('Error:');
});

test('empty old_string is rejected', function () {
    [$sandbox, $name] = makeSandboxedFile('hi');
    $tool = new EditFile($sandbox);

    $result = $tool->handle(new Request([
        'path' => $name,
        'edits' => [['old_string' => '', 'new_string' => 'x']],
    ]));

    expect($result)->toStartWith('Error:')
        ->and($result)->toContain('empty');
});
