<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Set the user’s current project. Subsequent tools that default to the current project will use this one.')]
class SwitchProjectTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'switch-project';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
        ]);

        $project = $this->resolveAccessibleProject($validated['project_id'], $user);
        if ($project instanceof Response) {
            return $project;
        }

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
