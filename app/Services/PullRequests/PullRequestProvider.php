<?php

namespace App\Services\PullRequests;

use App\Models\Repo;

/**
 * Strategy for opening a PR / merge request after a successful subtask push.
 *
 * Selected by `PullRequestManager::for($repo)` based on the Repo's provider
 * enum. Failures bubble up as Throwables; the pipeline records them as
 * `pull_request_error` without failing the run (see ADR-0004).
 */
interface PullRequestProvider
{
    /**
     * Create a pull / merge request from $head into $base on the given repo.
     *
     * @return array{url: string, number: int|string, id: int|string}
     */
    public function createPullRequest(Repo $repo, string $head, string $base, string $title, ?string $body = null): array;
}
