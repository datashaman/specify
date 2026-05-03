<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Manages the on-disk git working directory for an AgentRun.
 *
 * Each public method is a thin wrapper around a `git` invocation. Auth tokens
 * are injected only into HTTPS clone URLs (never logged) and the working
 * directory layout is `{runs_path}/specify/{feature-slug}/{story-slug}` so
 * concurrent subtasks of the same story share one checkout.
 */
class WorkspaceRunner
{
    public function __construct(public string $basePath, public string $committerName, public string $committerEmail) {}

    /** Build a `WorkspaceRunner` from `config/specify.php` values. */
    public static function fromConfig(): self
    {
        return new self(
            basePath: config('specify.runs_path'),
            committerName: config('specify.git.name'),
            committerEmail: config('specify.git.email'),
        );
    }

    /**
     * Resolve the working-directory path for an AgentRun.
     *
     * Subtask runs share `{base}/specify/{feature-slug}/{story-slug}` so all
     * subtasks of a story collaborate on one branch; runs without a slug
     * fall back to a per-run directory.
     */
    public function workingDirFor(AgentRun $run): string
    {
        $base = rtrim($this->basePath, '/');

        if ($run->runnable_type === Subtask::class) {
            $subtask = $run->runnable;
            $story = $subtask?->task?->story;
            $feature = $story?->feature;
            if ($feature && $story && $feature->slug && $story->slug) {
                return $base.'/specify/'.$feature->slug.'/'.$story->slug;
            }
        }

        return $base.'/run-'.$run->getKey();
    }

    /**
     * Clone the repo for this run, or fetch the latest if the working directory already exists.
     * Returns the absolute path to the working directory.
     */
    public function prepare(Repo $repo, AgentRun $run): string
    {
        File::ensureDirectoryExists($this->basePath);
        $dir = $this->workingDirFor($run);
        $url = $this->authenticatedUrl($repo);

        if (is_dir($dir.'/.git')) {
            $this->run(['git', 'fetch', '--all', '--prune'], $dir);

            return $dir;
        }

        File::ensureDirectoryExists($dir);
        $this->run(['git', 'clone', $url, $dir], cwd: null);

        return $dir;
    }

    /**
     * Check out `$branch`, creating it from `$baseBranch` if it doesn't exist.
     *
     * When `$baseBranch` is supplied, hard-resets the local copy to its remote
     * tracking branch first so the executor starts from a clean upstream.
     *
     * If `origin/{branch}` exists, the local branch is hard-reset to it so a
     * second run on the same branch picks up commits the previous run pushed
     * (or commits a teammate added). Without this, the per-run working dir's
     * stale local tip can drift from origin and the agent ends up working
     * against an out-of-date snapshot — the bug behind the
     * "subtask is already implemented" no-diff loop.
     *
     * "Remote absent, local exists" stays as-is (no upstream to sync to —
     * the local branch is the only source of truth).
     */
    public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void
    {
        if ($baseBranch !== null) {
            $this->run(['git', 'fetch', 'origin', $baseBranch], $workingDir);
            $this->run(['git', 'checkout', $baseBranch], $workingDir);
            $this->run(['git', 'reset', '--hard', 'origin/'.$baseBranch], $workingDir);
        }

        $this->run(['git', 'fetch', 'origin', $branch], $workingDir, allowFailure: true);
        $remote = $this->run(['git', 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$branch], $workingDir, allowFailure: true);
        $localExists = $this->run(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch], $workingDir, allowFailure: true);

        if ($remote['exitCode'] === 0) {
            if ($localExists['exitCode'] === 0) {
                $this->run(['git', 'checkout', $branch], $workingDir);
            } else {
                $this->run(['git', 'checkout', '-b', $branch, 'origin/'.$branch], $workingDir);
            }
            $this->run(['git', 'reset', '--hard', 'origin/'.$branch], $workingDir);

            return;
        }

        if ($localExists['exitCode'] === 0) {
            $this->run(['git', 'checkout', $branch], $workingDir);

            return;
        }

        $this->run(['git', 'checkout', '-b', $branch], $workingDir);
    }

    /**
     * Stage all changes and commit. Returns the new commit SHA, or null when nothing was committed.
     */
    public function commit(string $workingDir, string $message): ?string
    {
        $this->run(['git', 'add', '-A'], $workingDir);

        $status = $this->run(['git', 'status', '--porcelain'], $workingDir);
        if (trim($status['stdout']) === '') {
            return null;
        }

        $this->run([
            'git',
            '-c', 'user.name='.$this->committerName,
            '-c', 'user.email='.$this->committerEmail,
            'commit', '-m', $message,
        ], $workingDir);

        $sha = $this->run(['git', 'rev-parse', 'HEAD'], $workingDir);

        return trim($sha['stdout']);
    }

    /**
     * Diff the current branch against $base (defaults to HEAD~1 if available, otherwise empty).
     */
    public function diff(string $workingDir, ?string $base = null): string
    {
        if ($base === null) {
            $hasParent = $this->run(['git', 'rev-parse', '--verify', '--quiet', 'HEAD~1'], $workingDir, allowFailure: true);
            if ($hasParent['exitCode'] !== 0) {
                return '';
            }
            $base = 'HEAD~1';
        }

        $diff = $this->run(['git', 'diff', $base, 'HEAD'], $workingDir);

        return $diff['stdout'];
    }

    /**
     * True when `$sha` is a commit reachable from HEAD on the working dir.
     *
     * Used by the pipeline to verify "already complete" evidence (ADR-0007):
     * the agent must cite commits that actually live on the branch, not
     * arbitrary or hallucinated SHAs.
     */
    public function isCommitReachableFromHead(string $workingDir, string $sha): bool
    {
        $sha = trim($sha);
        if ($sha === '' || ! preg_match('/^[0-9a-f]{4,40}$/i', $sha)) {
            return false;
        }

        $check = $this->run(
            ['git', 'merge-base', '--is-ancestor', $sha, 'HEAD'],
            $workingDir,
            allowFailure: true,
        );

        return $check['exitCode'] === 0;
    }

    /** Recursively delete the working directory if it exists. */
    public function cleanup(string $workingDir): void
    {
        if (is_dir($workingDir)) {
            File::deleteDirectory($workingDir);
        }
    }

    /**
     * Discard any local-only commits on $branch by hard-resetting it to
     * `origin/$baseBranch` and clearing the working tree (ADR-0010).
     *
     * Called when the pipeline observes a cooperative cancel mid-flight:
     * a partial commit on the local working branch must not survive into
     * the shared per-story workspace, otherwise a future retry would start
     * from the cancelled commit. If the branch was already pushed
     * (origin/<branch> exists), the local copy is reset to the remote
     * tip rather than the base — the published work is the source of
     * truth and only local-ahead commits get discarded.
     */
    public function discardLocalChanges(string $workingDir, string $branch, string $baseBranch): void
    {
        $this->run(['git', 'reset', '--hard', 'HEAD'], $workingDir, allowFailure: true);
        $this->run(['git', 'clean', '-fdx'], $workingDir, allowFailure: true);

        $remote = $this->run(
            ['git', 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$branch],
            $workingDir,
            allowFailure: true,
        );

        if ($remote['exitCode'] === 0) {
            $this->run(['git', 'reset', '--hard', 'origin/'.$branch], $workingDir, allowFailure: true);

            return;
        }

        $this->run(['git', 'fetch', 'origin', $baseBranch], $workingDir, allowFailure: true);
        $this->run(['git', 'reset', '--hard', 'origin/'.$baseBranch], $workingDir, allowFailure: true);
    }

    /**
     * Push the named branch to origin so reviewers can inspect the diff out-of-band.
     */
    public function push(string $workingDir, string $branch): void
    {
        $this->run(['git', 'push', '--set-upstream', 'origin', $branch], $workingDir);
    }

    /**
     * `git merge --no-ff origin/{baseBranch}` — allow failure so callers can detect conflicts.
     */
    public function mergeNoFfFromOrigin(string $workingDir, string $baseBranch): int
    {
        $r = $this->run(
            ['git', 'merge', '--no-ff', 'origin/'.$baseBranch],
            $workingDir,
            allowFailure: true,
        );

        return $r['exitCode'] ?? 1;
    }

    public function resetHardToOriginBranch(string $workingDir, string $branch): void
    {
        $this->run(['git', 'reset', '--hard', 'origin/'.$branch], $workingDir);
    }

    public function currentHeadSha(string $workingDir): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD'], $workingDir)['stdout']);
    }

    /**
     * @return list<string>
     */
    public function unmergedPaths(string $workingDir): array
    {
        $r = $this->run(['git', 'diff', '--name-only', '--diff-filter=U'], $workingDir, allowFailure: true);
        $lines = array_filter(array_map('trim', explode("\n", $r['stdout'])));

        return array_values($lines);
    }

    public function hasUnmergedPaths(string $workingDir): bool
    {
        return $this->unmergedPaths($workingDir) !== [];
    }

    public function mergeAbort(string $workingDir): void
    {
        $this->run(['git', 'merge', '--abort'], $workingDir, allowFailure: true);
    }

    public function fetchOriginBranch(string $workingDir, string $branch): void
    {
        $this->run(['git', 'fetch', 'origin', $branch], $workingDir);
    }

    /**
     * Inject the access token into HTTPS URLs so authenticated clones work without a credential helper.
     * Local file:// URLs and SSH URLs are returned unchanged.
     */
    private function authenticatedUrl(Repo $repo): string
    {
        $url = $repo->url;
        $token = $repo->access_token;

        if ($token === null || $token === '') {
            return $url;
        }

        if (! str_starts_with($url, 'https://')) {
            return $url;
        }

        $parts = parse_url($url);
        if (! isset($parts['host'])) {
            return $url;
        }

        $userinfo = 'x-access-token:'.rawurlencode($token);
        $rebuilt = 'https://'.$userinfo.'@'.$parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $rebuilt .= '?'.$parts['query'];
        }

        return $rebuilt;
    }

    /**
     * @param  array<int, string>  $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function run(array $command, ?string $cwd, bool $allowFailure = false): array
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(120);
        $process->run();

        $result = [
            'exitCode' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];

        if (! $allowFailure && $result['exitCode'] !== 0) {
            throw new RuntimeException(sprintf(
                'Git command failed (%d): %s%s%s',
                $result['exitCode'],
                implode(' ', $command),
                PHP_EOL,
                $result['stderr'] ?: $result['stdout'],
            ));
        }

        return $result;
    }
}
