<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use App\Services\Executors\Executor;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sequences a single Subtask AgentRun: prepare the workspace, run the
 * executor, commit, push, open a PR. Returns a SubtaskRunOutcome the queue
 * job translates into AgentRun status changes.
 *
 * The pipeline owns step-by-step logging and the workspace/git/PR
 * coordination. The job is responsible only for queue lifecycle (load run,
 * markRunning, mark{Succeeded,Failed} from the outcome, rethrow on
 * exception).
 */
class SubtaskRunPipeline
{
    public function __construct(
        public Executor $executor,
        public WorkspaceRunner $workspace,
    ) {}

    /**
     * Run the full prepare → execute → commit → push → PR sequence for an AgentRun.
     *
     * Returns a `SubtaskRunOutcome` describing how the run terminated; the caller
     * (`ExecuteSubtaskJob`) translates that into AgentRun status changes. PR
     * failures are non-fatal (see ADR-0004) and surface as a distinct outcome.
     *
     * @throws RuntimeException When the AgentRun's runnable is not a Subtask, or
     *                          when the executor needs a working directory but
     *                          no repo is bound to the run.
     */
    public function run(AgentRun $agentRun): SubtaskRunOutcome
    {
        $subtask = $agentRun->runnable;
        if (! $subtask instanceof Subtask) {
            throw new RuntimeException('Subtask for AgentRun not found.');
        }

        $logCtx = $this->logContext($agentRun, $subtask);
        Log::info('specify.subtask.run.starting', $logCtx + ['subtask_name' => $subtask->name]);

        $repo = $agentRun->repo;
        $workingDir = null;

        if ($this->executor->needsWorkingDirectory()) {
            if ($repo === null) {
                throw new RuntimeException('Executor requires a repo, but none is bound to this AgentRun.');
            }

            $branch = $this->branchFor($agentRun);
            $workingDir = $this->workspace->prepare($repo, $agentRun);
            $this->workspace->checkoutBranch($workingDir, $branch, baseBranch: $repo->default_branch);
            Log::info('specify.subtask.workspace.ready', $logCtx + ['working_dir' => $workingDir]);
        }

        $result = $this->executor->execute($subtask, $workingDir, $repo, $agentRun->working_branch);
        $output = $result->toArray();

        if ($workingDir === null) {
            $diff = $result->filesChanged === [] ? null : implode("\n", $result->filesChanged);
            $output['commit_sha'] = null;
            Log::info('specify.subtask.run.succeeded', $logCtx + ['commit_sha' => null]);

            return SubtaskRunOutcome::succeeded($output, $diff);
        }

        $commitSha = $this->workspace->commit($workingDir, $result->commitMessage ?: 'specify: agent run');
        $diff = $this->workspace->diff($workingDir);

        Log::info('specify.subtask.commit', $logCtx + [
            'commit_sha' => $commitSha,
            'diff_bytes' => strlen((string) $diff),
        ]);

        if ($commitSha === null) {
            $summary = trim($result->summary);
            $reason = 'Agent produced no diff. The subtask was not executed.';
            if ($summary !== '') {
                $reason .= ' Agent summary: '.$summary;
            }
            Log::warning('specify.subtask.no_diff', $logCtx + ['summary' => $summary]);

            return SubtaskRunOutcome::noDiff($reason);
        }

        $output['commit_sha'] = $commitSha;

        if (config('specify.workspace.push_after_commit', true)) {
            $branch = $this->branchFor($agentRun);
            $this->workspace->push($workingDir, $branch);
            $output['pushed'] = true;
            Log::info('specify.subtask.push', $logCtx);

            if (config('specify.workspace.open_pr_after_push', true) && $repo !== null) {
                $prResult = $this->openPullRequest($repo, $subtask, $branch, $output);
                $output = array_merge($output, $prResult);

                if (isset($prResult['pull_request_error'])) {
                    Log::warning('specify.subtask.pr_failed', $logCtx + ['error' => $prResult['pull_request_error']]);

                    return SubtaskRunOutcome::pullRequestFailed(
                        $output,
                        'Pull request creation failed: '.$prResult['pull_request_error'],
                    );
                }

                if (isset($prResult['pull_request_url'])) {
                    Log::info('specify.subtask.pr_opened', $logCtx + ['url' => $prResult['pull_request_url']]);
                }
            }
        }

        Log::info('specify.subtask.run.succeeded', $logCtx + ['commit_sha' => $commitSha]);

        return SubtaskRunOutcome::succeeded($output, $diff);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function openPullRequest(Repo $repo, Subtask $subtask, string $branch, array $output): array
    {
        $provider = $repo->pullRequestProvider();
        if ($provider === null) {
            return ['pull_request_skipped' => 'unsupported_provider'];
        }

        try {
            $pr = $provider->createPullRequest(
                repo: $repo,
                head: $branch,
                base: $repo->default_branch,
                title: $this->prTitle($subtask),
                body: $this->prBody($subtask, $output),
            );

            return [
                'pull_request_url' => $pr['url'],
                'pull_request_number' => $pr['number'],
            ];
        } catch (Throwable $e) {
            return ['pull_request_error' => $e->getMessage()];
        }
    }

    /**
     * Build a scannable PR title that locates the work in the Story/AC tree.
     *
     * Reviewers triaging a queue of agent PRs benefit from the story id and
     * AC position — the bare subtask name does not place the change.
     */
    private function prTitle(Subtask $subtask): string
    {
        $task = $subtask->task;
        $story = $task?->story;
        $acPos = $task?->acceptanceCriterion?->position;

        $tag = $story && $acPos
            ? sprintf('[Story #%d AC#%d]', $story->getKey(), $acPos)
            : ($story ? sprintf('[Story #%d]', $story->getKey()) : '');

        return trim('Specify '.$tag.': '.$subtask->name);
    }

    /**
     * Render a structured PR body so reviewers see what changed, why it
     * satisfies the acceptance criterion, and which files to look at — in
     * place of a single opaque summary line.
     *
     * @param  array<string, mixed>  $output
     */
    private function prBody(Subtask $subtask, array $output): string
    {
        $task = $subtask->task;
        $story = $task?->story;
        $criterion = $task?->acceptanceCriterion?->criterion;
        $summary = trim((string) ($output['summary'] ?? ''));
        $files = (array) ($output['files_changed'] ?? []);

        $sections = [];

        if ($story !== null) {
            $sections[] = "## Story\n".$story->name;
        }

        if ($criterion !== null && $criterion !== '') {
            $sections[] = "## Acceptance Criterion\n".$criterion;
        }

        $sections[] = "## What changed\n".($summary !== '' ? $summary : '_(no summary provided)_');

        if ($files !== []) {
            $list = implode("\n", array_map(fn ($f) => '- `'.$f.'`', $files));
            $sections[] = "## Files\n".$list;
        }

        $clarifications = (array) ($output['clarifications'] ?? []);
        if ($clarifications !== []) {
            $rendered = [];
            foreach ($clarifications as $c) {
                if (is_array($c) && isset($c['message'])) {
                    $kind = isset($c['kind']) ? '['.$c['kind'].'] ' : '';
                    $rendered[] = '- '.$kind.$c['message'];
                }
            }
            if ($rendered !== []) {
                $sections[] = "## Open questions\n".implode("\n", $rendered);
            }
        }

        $sections[] = '_Specify: human approval recorded on the Story; this PR is the diff-review surface._';

        return implode("\n\n", $sections);
    }

    private function branchFor(AgentRun $agentRun): string
    {
        return $agentRun->working_branch ?? 'specify/run-'.$agentRun->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(AgentRun $agentRun, Subtask $subtask): array
    {
        return [
            'run_id' => $agentRun->getKey(),
            'subtask_id' => $subtask->getKey(),
            'story_id' => $subtask->task?->story_id,
            'branch' => $agentRun->working_branch,
        ];
    }
}
