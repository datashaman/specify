<?php

namespace App\Mcp\Tools;

use App\Enums\StoryKind;
use App\Enums\StoryStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\Stories\StoryWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: create-story
 */
#[Description('Create a story under an existing feature. The feature must already exist — use create_feature first if needed.')]
class CreateStoryTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'create-story';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request, StoryWriter $stories): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'feature_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', 'string', 'in:'.implode(',', array_column(StoryKind::cases(), 'value'))],
            'actor' => ['nullable', 'string'],
            'intent' => ['nullable', 'string'],
            'outcome' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(StoryStatus::cases(), 'value'))],
            'acceptance_criteria' => ['nullable', 'array'],
            'acceptance_criteria.*' => ['required', 'string'],
        ]);

        $feature = $this->resolveAccessibleFeature(
            $validated['feature_id'],
            $user,
            notFoundMessage: 'Feature not found. Use create_feature first.',
        );
        if ($feature instanceof Response) {
            return $feature;
        }

        $story = $stories->create($feature, $user, [
            'name' => $validated['name'],
            'kind' => isset($validated['kind']) ? StoryKind::from($validated['kind']) : StoryKind::UserStory,
            'actor' => $validated['actor'] ?? null,
            'intent' => $validated['intent'] ?? null,
            'outcome' => $validated['outcome'] ?? null,
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => isset($validated['status']) ? StoryStatus::from($validated['status']) : StoryStatus::Draft,
            'acceptance_criteria' => $validated['acceptance_criteria'] ?? [],
        ]);

        return Response::json([
            'id' => $story->id,
            'feature_id' => $story->feature_id,
            'name' => $story->name,
            'kind' => $story->kind?->value,
            'actor' => $story->actor,
            'intent' => $story->intent,
            'outcome' => $story->outcome,
            'description' => $story->description,
            'notes' => $story->notes,
            'status' => $story->status?->value,
            'revision' => $story->revision,
            'acceptance_criteria' => $story->acceptanceCriteria()->orderBy('position')
                ->get(['id', 'position', 'statement'])
                ->map(fn ($ac) => [
                    'id' => $ac->id,
                    'position' => $ac->position,
                    'statement' => $ac->statement,
                    'met' => (bool) $ac->met,
                ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        $statuses = array_column(StoryStatus::cases(), 'value');
        $kinds = array_column(StoryKind::cases(), 'value');

        return [
            'feature_id' => $schema->integer()
                ->description('Feature this story belongs to. Required.')
                ->required(),
            'name' => $schema->string()
                ->description('Story name. Required.')
                ->required(),
            'kind' => $schema->string()->description('Story kind. One of: '.implode(', ', $kinds)),
            'actor' => $schema->string()->description('Optional "As a ..." actor or role.'),
            'intent' => $schema->string()->description('Optional "I want ..." intent statement.'),
            'outcome' => $schema->string()->description('Optional "So that / in order to ..." outcome statement.'),
            'description' => $schema->string()->description('Product-owner framing and extra context for the unit of value. No schemas, classes, file paths, or implementation steps — those go in tasks/subtasks via set-tasks. Markdown supported.'),
            'notes' => $schema->string()->description('Anything that doesn’t fit the user-story format — caveats, links, scope reminders. Implementation steps belong in tasks/subtasks. Markdown supported.'),
            'status' => $schema->string()
                ->description('Story status. Defaults to "draft". One of: '.implode(', ', $statuses)),
            'acceptance_criteria' => $schema->array()
                ->description('Observable behaviour, one atomic rule statement per entry. Do not put full Given/When/Then scenarios here; those belong in scenarios. Created in order; positions auto-assigned starting at 1.'),
        ];
    }
}
