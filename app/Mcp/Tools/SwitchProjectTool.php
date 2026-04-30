<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Set the user’s current project. Subsequent tools that default to the current project will use this one.')]
class SwitchProjectTool extends Tool
{
    protected string $name = 'switch-project';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
        ]);

        $projectId = $validated['project_id'];

        if (! in_array($projectId, $user->accessibleProjectIds(), true)) {
            return Response::error('Project not accessible.');
        }

        $project = Project::query()->findOrFail($projectId);

        $user->switchProject($project);

        return Response::json([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'team_id' => $user->fresh()->current_team_id,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Project to switch to.')
                ->required(),
        ];
    }
}
