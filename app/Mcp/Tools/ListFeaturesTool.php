<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Feature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List features in a project. Defaults to the authenticated user’s current project.')]
class ListFeaturesTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-features';

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

        $features = $project->features()
            ->orderBy('name')
            ->get(['id', 'project_id', 'name', 'description', 'status']);

        return Response::json([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'features' => $features->map(fn (Feature $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'description' => $f->description,
                'status' => $f->status?->value,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Project to list features for. Defaults to the user’s current project.'),
        ];
    }
}
