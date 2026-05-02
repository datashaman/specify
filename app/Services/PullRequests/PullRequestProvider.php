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

    /**
     * Locate an *open* pull / merge request on `$repo` whose source branch is
     * `$head`. Used by `ExecutionService::retryPullRequestOpen` (ADR-0010) to
     * make PR retry idempotent — if a previous open call actually succeeded
     * on the upstream side and only the response was lost, adopt the
     * existing PR rather than open a duplicate.
     *
     * Implementations that don't support lookup return null; the retry then
     * falls back to attempting create and surfacing the duplicate-PR error
     * (ADR-0004 — non-fatal).
     *
     * @return array{url: string, number: int|string, id: int|string}|null
     */
    public function findOpenPullRequest(Repo $repo, string $head): ?array;
}
