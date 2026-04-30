<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Mcp\Auth;
use App\Models\Feature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
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
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(StoryStatus::cases(), 'value'))],
            'acceptance_criteria' => ['nullable', 'array'],
            'acceptance_criteria.*' => ['required', 'string'],
        ]);

        $feature = Feature::query()->find($validated['feature_id']);
        if (! $feature) {
            return Response::error('Feature not found. Use create_feature first.');
        }

        if (! in_array($feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Feature not accessible.');
        }

        $story = DB::transaction(function () use ($feature, $user, $validated) {
            $story = $feature->stories()->create([
                'created_by_id' => $user->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => isset($validated['status']) ? StoryStatus::from($validated['status']) : StoryStatus::Draft,
                'revision' => 1,
            ]);

            foreach (($validated['acceptance_criteria'] ?? []) as $i => $criterion) {
                $story->acceptanceCriteria()->create([
                    'criterion' => $criterion,
                    'position' => $i + 1,
                ]);
            }

            return $story;
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
            'feature_id' => $schema->integer()
                ->description('Feature this story belongs to. Required.')
                ->required(),
            'name' => $schema->string()
                ->description('Story name. Required.')
                ->required(),
            'description' => $schema->string()->description('Product-owner framing of the unit of value, typically "as a {role}, I {want}, so that {outcome}." No schemas, classes, file paths, or implementation steps — those go in tasks/subtasks via set-tasks. Markdown supported.'),
            'notes' => $schema->string()->description('Anything that doesn’t fit the user-story format — caveats, links, scope reminders. Implementation steps belong in tasks/subtasks. Markdown supported.'),
            'status' => $schema->string()
                ->description('Story status. Defaults to "draft". One of: '.implode(', ', $statuses)),
            'acceptance_criteria' => $schema->array()
                ->description('Observable behaviour, one statement per entry. "Given/when/then" or plain "the system X when Y" both work. Implementation steps do not belong here — those go in tasks/subtasks. Created in order; positions auto-assigned starting at 1.'),
        ];
    }
}
