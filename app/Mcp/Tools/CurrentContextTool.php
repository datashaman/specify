<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Return the acting user, their current workspace, current team, and current project. Useful for orienting at the start of a session.')]
class CurrentContextTool extends Tool
{
    protected string $name = 'current-context';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $workspace = $user->currentWorkspace();
        $team = $user->currentTeam;
        $project = $user->currentProject;

        return Response::json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
            ] : null,
            'team' => $team ? [
                'id' => $team->id,
                'name' => $team->name,
            ] : null,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status?->value,
            ] : null,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
