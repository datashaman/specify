<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Mark a repo as the project’s primary. Clears any other primary on the project.')]
class SetPrimaryRepoTool extends Tool
{
    protected string $name = 'set-primary-repo';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'repo_id' => ['required', 'integer'],
        ]);

        $projectId = $validated['project_id'] ?? $user->current_project_id;
        if (! $projectId) {
            return Response::error('No project_id provided and no current project set.');
        }

        if (! in_array($projectId, $user->accessibleProjectIds(), true)) {
            return Response::error('Project not accessible.');
        }

        $project = Project::query()->findOrFail($projectId);

        if (! $user->canApproveInProject($project)) {
            return Response::error('You do not have manage rights in this project.');
        }

        $repo = $project->repos()->find($validated['repo_id']);
        if (! $repo) {
            return Response::error('Repo not attached to this project.');
        }

        $project->setPrimaryRepo($repo);

        return Response::json([
            'project_id' => $project->id,
            'primary_repo_id' => $repo->id,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project. Defaults to the user’s current project.'),
            'repo_id' => $schema->integer()->description('Repo to mark primary. Must already be attached.')->required(),
        ];
    }
}
