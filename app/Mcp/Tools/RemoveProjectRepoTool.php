<?php

namespace App\Mcp\Tools;

use App\Enums\RepoProvider;
use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: remove-project-repo
 */
#[Description('Remove a repo from a project. Deletes the GitHub webhook, detaches the pivot, and deletes the Repo row. Mirrors the Repos page Remove button.')]
class RemoveProjectRepoTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'remove-project-repo';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'repo_id' => ['required', 'integer'],
        ]);

        $projectId = $validated['project_id'] ?? $user->current_project_id;
        if (! $projectId) {
            return Response::error('No project_id provided and no current project set.');
        }

        $project = $this->resolveAccessibleProject($projectId, $user);
        if ($project instanceof Response) {
            return $project;
        }

        if (! $user->canApproveInProject($project)) {
            return Response::error('You do not have manage rights in this project.');
        }

        $repo = $project->repos()->find($validated['repo_id']);
        if (! $repo) {
            return Response::error('Repo not attached to this project.');
        }

        if ($repo->provider === RepoProvider::Github) {
            $repo->deleteGithubWebhook();
        }

        $repo->projects()->detach();
        $repo->delete();

        return Response::json([
            'project_id' => $project->id,
            'removed_repo_id' => $validated['repo_id'],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project to remove from. Defaults to the user’s current project.'),
            'repo_id' => $schema->integer()->description('Repo to remove.')->required(),
        ];
    }
}
