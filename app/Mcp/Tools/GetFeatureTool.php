<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get a feature in detail, including story count.')]
class GetFeatureTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'get-feature';

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
        $feature->loadCount('stories');

        return Response::json([
            'id' => $feature->id,
            'project_id' => $feature->project_id,
            'name' => $feature->name,
            'description' => $feature->description,
            'status' => $feature->status?->value,
            'stories_count' => $feature->stories_count,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'feature_id' => $schema->integer()
                ->description('Feature to fetch.')
                ->required(),
        ];
    }
}
