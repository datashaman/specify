<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
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
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
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
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => isset($validated['status']) ? StoryStatus::from($validated['status']) : null,
        ], fn ($v) => $v !== null);

        $hasCriteriaUpdate = array_key_exists('acceptance_criteria', $validated);

        if (! $changes && ! $hasCriteriaUpdate) {
            return Response::error('Provide at least one of: name, description, notes, status, acceptance_criteria.');
        }

        DB::transaction(function () use ($story, $changes, $validated, $hasCriteriaUpdate) {
            if ($changes) {
                $story->fill($changes)->save();
            }

            if ($hasCriteriaUpdate) {
                $story->acceptanceCriteria()->delete();
                foreach ($validated['acceptance_criteria'] as $i => $criterion) {
                    $story->acceptanceCriteria()->create([
                        'criterion' => $criterion,
                        'position' => $i + 1,
                    ]);
                }
            }
        });

        return Response::json([
            'id' => $story->id,
            'feature_id' => $story->feature_id,
            'name' => $story->name,
            'description' => $story->description,
            'notes' => $story->notes,
            'status' => $story->status?->value,
            'revision' => $story->revision,
            'acceptance_criteria' => $story->acceptanceCriteria()->orderBy('position')
                ->get(['id', 'position', 'criterion'])
                ->map(fn ($ac) => [
                    'id' => $ac->id,
                    'position' => $ac->position,
                    'criterion' => $ac->criterion,
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

        return [
            'story_id' => $schema->integer()->description('Story to update.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('Product-owner framing — "as a {role}, I {want}, so that {outcome}." No implementation detail (schemas, classes, file paths). Markdown supported.'),
            'notes' => $schema->string()->description('Caveats, links, scope reminders. Markdown supported.'),
            'status' => $schema->string()
                ->description('New status. One of: '.implode(', ', $statuses)),
            'acceptance_criteria' => $schema->array()
                ->description('Observable behaviour, one statement per entry. Replaces the story’s criteria with this list. Pass [] to clear. Omit to leave existing criteria untouched.'),
        ];
    }
}
