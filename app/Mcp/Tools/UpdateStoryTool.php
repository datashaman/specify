<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Mcp\Auth;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an existing story’s name, description, or status.')]
class UpdateStoryTool extends Tool
{
    protected string $name = 'update-story';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(StoryStatus::cases(), 'value'))],
        ]);

        $story = Story::query()->with('feature')->find($validated['story_id']);
        if (! $story) {
            return Response::error('Story not found.');
        }

        if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Story not accessible.');
        }

        $changes = array_filter([
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => isset($validated['status']) ? StoryStatus::from($validated['status']) : null,
        ], fn ($v) => $v !== null);

        if (! $changes) {
            return Response::error('Provide at least one of: name, description, status.');
        }

        $story->fill($changes)->save();

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
            'story_id' => $schema->integer()->description('Story to update.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description.'),
            'status' => $schema->string()
                ->description('New status. One of: '.implode(', ', $statuses)),
        ];
    }
}
