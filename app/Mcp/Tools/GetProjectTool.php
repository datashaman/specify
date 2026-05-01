<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: get-project
 */
#[Description('Get a project in detail (counts of features, stories, repos). Defaults to the user’s current project.')]
class GetProjectTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'get-project';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $projectId = $request->integer('project_id') ?: $user->current_project_id;
        if (! $projectId) {
            return Response::error('No project_id provided and no current project set.');
        }

        $project = $this->resolveAccessibleProject($projectId, $user);
        if ($project instanceof Response) {
            return $project;
        }
        $project->load('team')->loadCount(['features', 'stories', 'repos']);

        $primary = $project->primaryRepo();

        return Response::json([
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status?->value,
            'team' => $project->team?->name,
            'features_count' => $project->features_count,
            'stories_count' => $project->stories_count,
            'repos_count' => $project->repos_count,
            'primary_repo' => $primary ? [
                'id' => $primary->id,
                'name' => $primary->name,
                'url' => $primary->url,
            ] : null,
            'is_current' => $project->id === $user->current_project_id,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Project to fetch. Defaults to the user’s current project.'),
        ];
    }
}
