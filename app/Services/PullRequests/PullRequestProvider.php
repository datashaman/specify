<?php

namespace App\Services\PullRequests;

use App\Models\Repo;

interface PullRequestProvider
{
    /**
     * Create a pull / merge request from $head into $base on the given repo.
     *
     * @return array{url: string, number: int|string, id: int|string}
     */
    public function createPullRequest(Repo $repo, string $head, string $base, string $title, ?string $body = null): array;
}
