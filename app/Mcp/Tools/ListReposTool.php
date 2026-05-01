<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Repo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List repositories attached to a project. Defaults to the user’s current project.')]
class ListReposTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-repos';

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
        $repos = $project->repos()->orderBy('name')->get();

        return Response::json([
            'project_id' => $project->id,
            'repos' => $repos->map(fn (Repo $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'provider' => $r->provider?->value,
                'url' => $r->url,
                'default_branch' => $r->default_branch,
                'is_primary' => (bool) ($r->pivot->is_primary ?? false),
                'has_webhook' => (bool) $r->webhook_secret,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project to list repos for. Defaults to the user’s current project.'),
        ];
    }
}
