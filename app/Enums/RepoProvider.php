<?php

namespace App\Enums;

/**
 * Hosting provider for a Repo. Drives PullRequestProvider selection
 * and provider-specific URL/auth handling. Generic is a passthrough
 * for self-hosted git over HTTPS without a PR API.
 */
enum RepoProvider: string
{
    case Github = 'github';
    case Gitlab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Generic = 'generic';
}
