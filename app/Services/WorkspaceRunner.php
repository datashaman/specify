<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class WorkspaceRunner
{
    public function __construct(public string $basePath, public string $committerName, public string $committerEmail) {}

    public static function fromConfig(): self
    {
        return new self(
            basePath: config('specify.runs_path'),
            committerName: config('specify.git.name'),
            committerEmail: config('specify.git.email'),
        );
    }

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

    public function checkoutBranch(string $workingDir, string $branch, ?string $baseBranch = null): void
    {
        if ($baseBranch !== null) {
            $this->run(['git', 'fetch', 'origin', $baseBranch], $workingDir);
            $this->run(['git', 'checkout', $baseBranch], $workingDir);
            $this->run(['git', 'reset', '--hard', 'origin/'.$baseBranch], $workingDir);
        }

        $exists = $this->run(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch], $workingDir, allowFailure: true);

        if ($exists['exitCode'] === 0) {
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

    public function cleanup(string $workingDir): void
    {
        if (is_dir($workingDir)) {
            File::deleteDirectory($workingDir);
        }
    }

    /**
     * Push the named branch to origin so reviewers can inspect the diff out-of-band.
     */
    public function push(string $workingDir, string $branch): void
    {
        $this->run(['git', 'push', '--set-upstream', 'origin', $branch], $workingDir);
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
