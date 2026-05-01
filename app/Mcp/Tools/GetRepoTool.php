<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Repo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get a repository in detail, including the latest commit on the default branch.')]
class GetRepoTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'get-repo';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $repoId = $request->integer('repo_id');
        if (! $repoId) {
            return Response::error('repo_id is required.');
        }

        $repo = Repo::query()->find($repoId);
        if (! $repo) {
            return Response::error('Repo not found.');
        }

        $accessible = $user->accessibleProjectIds();
        $projectIds = $repo->projects()->pluck('projects.id')->all();
        if (empty(array_intersect($accessible, $projectIds))) {
            return Response::error('Repo not accessible.');
        }

        $commit = $repo->latestCommit();

        return Response::json([
            'id' => $repo->id,
            'name' => $repo->name,
            'provider' => $repo->provider?->value,
            'url' => $repo->url,
            'default_branch' => $repo->default_branch,
            'has_token' => (bool) $repo->access_token,
            'has_webhook' => (bool) $repo->webhook_secret,
            'project_ids' => $projectIds,
            'latest_commit' => $commit,
            'latest_commit_error' => $commit ? null : $repo->latestCommitError(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repo_id' => $schema->integer()->description('Repo to fetch.')->required(),
        ];
    }
}
