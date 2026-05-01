<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List stories under a feature.')]
class ListStoriesTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-stories';

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
