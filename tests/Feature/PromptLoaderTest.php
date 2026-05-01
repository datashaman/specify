<?php

use App\Ai\Agents\SubtaskExecutor;
use App\Ai\Agents\TasksGenerator;
use App\Models\Story;
use App\Models\Subtask;
use App\Services\Prompts\PromptLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('PromptLoader reads a markdown file by short name and trims it', function () {
    $tmp = sys_get_temp_dir().'/specify-prompts-'.uniqid();
    File::ensureDirectoryExists($tmp);
    File::put($tmp.'/hello.md', "\n  hi from disk  \n\n");

    $loader = new PromptLoader($tmp);

    expect($loader->load('hello'))->toBe('hi from disk');

    File::deleteDirectory($tmp);
});

test('PromptLoader caches subsequent loads of the same name', function () {
    $tmp = sys_get_temp_dir().'/specify-prompts-'.uniqid();
    File::ensureDirectoryExists($tmp);
    File::put($tmp.'/x.md', 'first');

    $loader = new PromptLoader($tmp);
    expect($loader->load('x'))->toBe('first');

    // Mutate the file on disk; the cached value should win.
    File::put($tmp.'/x.md', 'second');
    expect($loader->load('x'))->toBe('first');

    File::deleteDirectory($tmp);
});

test('PromptLoader throws when the file is missing', function () {
    $loader = new PromptLoader(sys_get_temp_dir().'/does-not-exist-'.uniqid());

    expect(fn () => $loader->load('nope'))->toThrow(RuntimeException::class, 'Prompt file not found');
});

test('SubtaskExecutor::instructions() pulls from the prompts/subtask-executor.md file', function () {
    $subtask = Subtask::factory()->create();
    $instructions = (new SubtaskExecutor($subtask))->instructions();

    expect($instructions)
        ->toContain('You are the execution agent for Specify.')
        ->toContain('clarifications')
        ->toContain('proposed_subtasks');
});

test('TasksGenerator::instructions() pulls from the prompts/tasks-generator.md file', function () {
    $story = Story::factory()->create();
    $instructions = (new TasksGenerator($story))->instructions();

    expect($instructions)
        ->toContain('You are the planning agent for Specify')
        ->toContain('one Task per Acceptance Criterion');
});
