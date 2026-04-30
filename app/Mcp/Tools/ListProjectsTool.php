<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List projects accessible to the authenticated user.')]
class ListProjectsTool extends Tool
{
    protected string $name = 'list-projects';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $projects = Project::query()
            ->whereIn('id', $user->accessibleProjectIds())
            ->with('team')
            ->orderBy('name')
            ->get(['id', 'team_id', 'name', 'description', 'status']);

        return Response::json($projects->map(fn (Project $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'status' => $p->status?->value,
            'team' => $p->team?->name,
            'is_current' => $p->id === $user->current_project_id,
        ])->all());
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
