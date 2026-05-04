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
 * MCP tool: update-story
 */
#[Description('Update an existing story’s name, description, or status.')]
class UpdateStoryTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-story';

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
            'story_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
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

        $story = $this->resolveAccessibleStory($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        $changes = array_filter([
            'name' => $validated['name'] ?? null,
            'kind' => isset($validated['kind']) ? StoryKind::from($validated['kind']) : null,
            'actor' => $validated['actor'] ?? null,
            'intent' => $validated['intent'] ?? null,
            'outcome' => $validated['outcome'] ?? null,
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => isset($validated['status']) ? StoryStatus::from($validated['status']) : null,
        ], fn ($v) => $v !== null);

        $hasCriteriaUpdate = array_key_exists('acceptance_criteria', $validated);

        if (! $changes && ! $hasCriteriaUpdate) {
            return Response::error('Provide at least one of: name, kind, actor, intent, outcome, description, notes, status, acceptance_criteria.');
        }

        $stories->update(
            $story,
            $changes,
            $hasCriteriaUpdate ? $validated['acceptance_criteria'] : null,
        );

        $story->refresh();

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
            'story_id' => $schema->integer()->description('Story to update.')->required(),
            'name' => $schema->string()->description('New name.'),
            'kind' => $schema->string()->description('New story kind. One of: '.implode(', ', $kinds)),
            'actor' => $schema->string()->description('New "As a ..." actor or role.'),
            'intent' => $schema->string()->description('New "I want ..." intent statement.'),
            'outcome' => $schema->string()->description('New "So that / in order to ..." outcome statement.'),
            'description' => $schema->string()->description('Product-owner framing and context. No implementation detail (schemas, classes, file paths, or implementation steps). Implementation belongs in Plans, Tasks, and Subtasks. Markdown supported.'),
            'notes' => $schema->string()->description('Caveats, links, scope reminders. Implementation steps belong in Plans, Tasks, and Subtasks. Markdown supported.'),
            'status' => $schema->string()
                ->description('New status. One of: '.implode(', ', $statuses)),
            'acceptance_criteria' => $schema->array()
                ->description('Observable behaviour, one atomic rule statement per entry. Replaces the story’s criteria with this list. Pass [] to clear. Omit to leave existing criteria untouched.'),
        ];
    }
}
