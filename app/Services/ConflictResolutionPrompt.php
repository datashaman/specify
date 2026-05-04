<?php

namespace App\Services;

use App\Models\Repo;
use App\Models\Subtask;
use App\Services\Prompts\PromptLoader;

/**
 * Builds the prompt passed to the configured executor for ResolveConflicts runs.
 */
final class ConflictResolutionPrompt
{
    /**
     * @param  list<string>  $unmergedPaths
     */
    public static function forExecutorContext(
        Subtask $subtask,
        Repo $repo,
        string $workingBranch,
        string $baseBranch,
        int $pullRequestNumber,
        array $unmergedPaths,
    ): string {
        $subtask->loadMissing('task.plan.story', 'task.acceptanceCriterion');
        $task = $subtask->task;
        $story = $task?->plan?->story;
        $criterion = $task?->acceptanceCriterion?->statement;

        $criterionBlock = $criterion ? "Acceptance Criterion: {$criterion}\n\n" : '';
        $taskBlock = $task ? "Parent Task #{$task->position}: {$task->name}\n" : '';

        $files = $unmergedPaths === []
            ? '(unknown — inspect git status)'
            : implode("\n", array_map(fn (string $p) => '- '.$p, $unmergedPaths));

        $repoLine = "Repository: {$repo->url} (working branch: {$workingBranch}, base: {$baseBranch})";

        $preamble = app(PromptLoader::class)->load('conflict-resolver-executor');

        $detail = implode("\n", array_filter([
            $story ? "Story: {$story->name}" : null,
            '',
            'Description:',
            $story ? (string) $story->description : '',
            '',
            $criterionBlock.$taskBlock.'Subtask #'.$subtask->position.': '.$subtask->name,
            '',
            'Subtask description:',
            (string) $subtask->description,
            '',
            $repoLine,
            '',
            "Open Pull Request: #{$pullRequestNumber}",
            '',
            "A merge of `origin/{$baseBranch}` into `{$workingBranch}` (`git merge --no-ff`) is in progress and has conflicts.",
            '',
            'Unmerged paths reported by git:',
            $files,
            '',
            'Resolve every conflict so `git` no longer reports unmerged paths.',
        ]));

        return $preamble."\n\n---\n\n".$detail;
    }
}
