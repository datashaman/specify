<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: list-stories
 */
#[Description('List stories under a feature.')]
class ListStoriesTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-stories';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $featureId = $request->integer('feature_id');
        if (! $featureId) {
            return Response::error('feature_id is required.');
        }

        $feature = $this->resolveAccessibleFeature($featureId, $user);
        if ($feature instanceof Response) {
            return $feature;
        }

        $stories = $feature->stories()
            ->orderBy('position')
            ->get(['id', 'feature_id', 'name', 'kind', 'actor', 'intent', 'outcome', 'description', 'status', 'revision', 'position']);

        return Response::json([
            'feature_id' => $feature->id,
            'feature_name' => $feature->name,
            'stories' => $stories->map(fn (Story $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'kind' => $s->kind?->value,
                'actor' => $s->actor,
                'intent' => $s->intent,
                'outcome' => $s->outcome,
                'description' => $s->description,
                'status' => $s->status?->value,
                'revision' => $s->revision,
                'position' => $s->position,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'feature_id' => $schema->integer()
                ->description('Feature to list stories for.')
                ->required(),
        ];
    }
}
