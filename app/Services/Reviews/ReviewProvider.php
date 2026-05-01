<?php

namespace App\Services\Reviews;

use App\Models\Repo;

/**
 * Strategy for posting an advisory review on a freshly-opened pull request.
 *
 * Mirrors `PullRequestProvider`. Selected by `Repo::reviewProvider()` based
 * on the Repo's provider enum. Failures bubble up as Throwables; the
 * dispatching job records them as `review_error` on the AgentRun without
 * failing the run (review is non-fatal, same posture as ADR-0004 for PR
 * creation).
 *
 * Reviews are always advisory — they post as a `COMMENT`-style review on
 * the host VCS, never `REQUEST_CHANGES` or `APPROVE`. The Story remains
 * the only approval gate (ADR-0001); this surface adds *signal*, not gates.
 */
interface ReviewProvider
{
    /**
     * Post a review with optional inline comments on the given PR.
     *
     * @param  list<ReviewComment>  $comments
     * @return array{url: ?string, id: int|string|null}
     */
    public function postReview(Repo $repo, int|string $pullRequestNumber, string $summary, array $comments): array;
}
