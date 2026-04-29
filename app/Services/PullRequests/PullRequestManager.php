<?php

namespace App\Services\PullRequests;

use App\Enums\RepoProvider;
use App\Models\Repo;

class PullRequestManager
{
    public function for(Repo $repo): ?PullRequestProvider
    {
        return match ($repo->provider) {
            RepoProvider::Github => app(GithubPullRequestProvider::class),
            RepoProvider::Gitlab => app(GitlabPullRequestProvider::class),
            RepoProvider::Bitbucket => app(BitbucketPullRequestProvider::class),
            default => null,
        };
    }
}
