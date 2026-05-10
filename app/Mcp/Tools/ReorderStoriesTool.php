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
 * MCP tool: reorder-stories
 */
#[Description('Reorder stories within a feature. Pass the full ordered list of story IDs (every story must be present); positions are rewritten 1..N to match. No-ops if the payload does not cover every story in the feature. Caller must have approve rights in the project.')]
class ReorderStoriesTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'reorder-stories';

    public function handle(Request $request, PositionReorderer $reorderer): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'feature_id' => ['required', 'integer'],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'integer'],
        ]);

        $feature = $this->resolveAccessibleFeature($validated['feature_id'], $user);
        if ($feature instanceof Response) {
            return $feature;
        }

        if (! $user->canApproveInProject($feature->project)) {
            return Response::error('You must have approve rights in this project to reorder stories.');
        }

        $applied = $reorderer->reorder('stories', 'feature_id', (int) $feature->id, $validated['ordered_ids']);

        if (! $applied) {
            return Response::error('ordered_ids must include every story in the feature. Pass the complete list in the new order.');
        }

        return Response::json([
            'feature_id' => $feature->id,
            'stories' => $feature->stories()->orderBy('position')
                ->get(['id', 'position', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'position' => $s->position, 'name' => $s->name])
                ->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'feature_id' => $schema->integer()->description('Feature whose stories to reorder.')->required(),
            'ordered_ids' => $schema->array()
                ->description('Full list of story IDs in the new visual order. Must contain every story in the feature; no-ops otherwise.'),
        ];
    }
}
