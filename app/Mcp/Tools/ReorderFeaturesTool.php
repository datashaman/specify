<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\Ordering\PositionReorderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: reorder-features
 */
#[Description('Reorder features within a project. Pass the full ordered list of feature IDs (every feature must be present); positions are rewritten 1..N to match. No-ops if the payload does not cover every feature in the project. Caller must have approve rights in the project.')]
class ReorderFeaturesTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'reorder-features';

    public function handle(Request $request, PositionReorderer $reorderer): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'integer'],
        ]);

        $projectId = $validated['project_id'] ?? $user->current_project_id;
        if (! $projectId) {
            return Response::error('No project_id provided and no current project set.');
        }

        $project = $this->resolveAccessibleProject((int) $projectId, $user);
        if ($project instanceof Response) {
            return $project;
        }

        if (! $user->canApproveInProject($project)) {
            return Response::error('You must have approve rights in this project to reorder features.');
        }

        $applied = $reorderer->reorder('features', 'project_id', (int) $project->id, $validated['ordered_ids']);

        if (! $applied) {
            return Response::error('ordered_ids must include every feature in the project. Pass the complete list in the new order.');
        }

        return Response::json([
            'project_id' => $project->id,
            'features' => $project->features()->orderBy('position')
                ->get(['id', 'position', 'name'])
                ->map(fn ($f) => ['id' => $f->id, 'position' => $f->position, 'name' => $f->name])
                ->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project whose features to reorder. Defaults to the user’s current project.'),
            'ordered_ids' => $schema->array()
                ->description('Full list of feature IDs in the new visual order. Must contain every feature in the project; no-ops otherwise.'),
        ];
    }
}
