<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get a project in detail (counts of features, stories, repos). Defaults to the user’s current project.')]
class GetProjectTool extends Tool
{
    protected string $name = 'get-project';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $projectId = $request->integer('project_id') ?: $user->current_project_id;
        if (! $projectId) {
            return Response::error('No project_id provided and no current project set.');
        }

        if (! in_array($projectId, $user->accessibleProjectIds(), true)) {
            return Response::error('Project not accessible.');
        }

        $project = Project::query()
            ->with('team')
            ->withCount(['features', 'stories', 'repos'])
            ->findOrFail($projectId);

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
