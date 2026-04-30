<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Feature;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List stories under a feature.')]
class ListStoriesTool extends Tool
{
    protected string $name = 'list-stories';

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

        $feature = Feature::query()->find($featureId);
        if (! $feature) {
            return Response::error('Feature not found.');
        }

        if (! in_array($feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Feature not accessible.');
        }

        $stories = $feature->stories()
            ->orderByDesc('id')
            ->get(['id', 'feature_id', 'name', 'description', 'status', 'revision']);

        return Response::json([
            'feature_id' => $feature->id,
            'feature_name' => $feature->name,
            'stories' => $stories->map(fn (Story $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'status' => $s->status?->value,
                'revision' => $s->revision,
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
