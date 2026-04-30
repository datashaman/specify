<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Mcp\Auth;
use App\Models\Feature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a story under an existing feature. The feature must already exist — use create_feature first if needed.')]
class CreateStoryTool extends Tool
{
    protected string $name = 'create-story';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'feature_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(StoryStatus::cases(), 'value'))],
        ]);

        $feature = Feature::query()->find($validated['feature_id']);
        if (! $feature) {
            return Response::error('Feature not found. Use create_feature first.');
        }

        if (! in_array($feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Feature not accessible.');
        }

        $story = $feature->stories()->create([
            'created_by_id' => $user->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => isset($validated['status']) ? StoryStatus::from($validated['status']) : StoryStatus::Draft,
            'revision' => 1,
        ]);

        return Response::json([
            'id' => $story->id,
            'feature_id' => $story->feature_id,
            'name' => $story->name,
            'description' => $story->description,
            'status' => $story->status?->value,
            'revision' => $story->revision,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        $statuses = array_column(StoryStatus::cases(), 'value');

        return [
            'feature_id' => $schema->integer()
                ->description('Feature this story belongs to. Required.')
                ->required(),
            'name' => $schema->string()
                ->description('Story name. Required.')
                ->required(),
            'description' => $schema->string()->description('Story description. Optional.'),
            'status' => $schema->string()
                ->description('Story status. Defaults to "draft". One of: '.implode(', ', $statuses)),
        ];
    }
}
