<?php

namespace App\Services\Stories;

use App\Enums\AgentRunKind;
use App\Enums\RepoProvider;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Story;
use App\Models\Subtask;
use App\Services\PullRequests\GithubPullRequestProbe;
use Illuminate\Support\Collection;

class StoryPullRequestProjection
{
    public function __construct(private GithubPullRequestProbe $githubProbe) {}

    /**
     * Pull requests associated with this Story.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(Story $story): Collection
    {
        $subtaskIds = $story->tasks()
            ->with('subtasks:id,task_id')
            ->get()
            ->flatMap(fn ($task) => $task->subtasks->pluck('id'))
            ->all();

        if ($subtaskIds === []) {
            return collect();
        }

        $entries = AgentRun::query()
            ->where('runnable_type', Subtask::class)
            ->whereIn('runnable_id', $subtaskIds)
            ->where('kind', AgentRunKind::Execute->value)
            ->whereJsonContainsKey('output->pull_request_url')
            ->orderByDesc('id')
            ->get(['id', 'repo_id', 'executor_driver', 'working_branch', 'output', 'finished_at'])
            ->map(function (AgentRun $run) {
                $output = (array) $run->output;
                $url = (string) ($output['pull_request_url'] ?? '');
                if ($url === '') {
                    return null;
                }

                return [
                    'url' => $url,
                    'number' => $output['pull_request_number'] ?? null,
                    'driver' => $run->executor_driver,
                    'branch' => $run->working_branch,
                    'run_id' => $run->getKey(),
                    'repo_id' => $run->repo_id,
                    'merged' => $output['pull_request_merged'] ?? null,
                    'action' => $output['pull_request_action'] ?? null,
                    'run_finished_at' => $run->finished_at,
                ];
            })
            ->filter()
            ->values();

        $entries = $entries
            ->groupBy('url')
            ->map(fn (Collection $group) => $group->first())
            ->values();

        $repoIds = $entries->pluck('repo_id')->filter()->unique()->values();
        $reposById = Repo::query()->whereIn('id', $repoIds)->get()->keyBy('id');

        return $entries
            ->map(function (array $pr) use ($reposById) {
                $repoId = $pr['repo_id'] ?? null;
                unset($pr['repo_id']);
                $pr['mergeable'] = null;
                $pr['mergeable_state'] = null;

                $number = $pr['number'] ?? null;
                if ($repoId && is_numeric($number)) {
                    $repo = $reposById->get($repoId);
                    if ($repo && $repo->provider === RepoProvider::Github) {
                        $result = $this->githubProbe->probeMergeable($repo, (int) $number);
                        if ($result !== null) {
                            $pr['mergeable'] = $result['mergeable'];
                            $pr['mergeable_state'] = $result['mergeable_state'];
                        }
                    }
                }

                return $pr;
            })
            ->sortBy(fn ($pr) => [
                $pr['merged'] === true ? 0 : 1,
                -1 * (int) $pr['run_id'],
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function primary(Story $story): ?array
    {
        $prs = $this->all($story);
        if ($prs->isEmpty()) {
            return null;
        }

        $merged = $prs->first(fn ($pr) => $pr['merged'] === true);
        if ($merged !== null) {
            return $merged;
        }

        return $prs->count() === 1 ? $prs->first() : null;
    }
}
