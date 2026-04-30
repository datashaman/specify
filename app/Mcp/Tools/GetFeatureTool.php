<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Feature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get a feature in detail, including story count.')]
class GetFeatureTool extends Tool
{
    protected string $name = 'get-feature';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $featureId = $request->integer('feature_id');
        if (! $featureId) {
            return Response::error('feature_id is required.');
        }

        $feature = Feature::query()->withCount('stories')->find($featureId);
        if (! $feature) {
            return Response::error('Feature not found.');
        }

        if (! in_array($feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Feature not accessible.');
        }

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
