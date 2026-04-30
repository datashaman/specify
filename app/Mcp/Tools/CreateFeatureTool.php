<?php

namespace App\Mcp\Tools;

use App\Enums\FeatureStatus;
use App\Mcp\Auth;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a feature in a project. Defaults to the authenticated user’s current project. Required before creating stories.')]
class CreateFeatureTool extends Tool
{
    protected string $name = 'create-feature';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(FeatureStatus::cases(), 'value'))],
        ]);

        $projectId = $validated['project_id'] ?? $user->current_project_id;
        if (! $projectId) {
            return Response::error('No project_id provided and no current project set.');
        }

        if (! in_array($projectId, $user->accessibleProjectIds(), true)) {
            return Response::error('Project not accessible.');
        }

        $project = Project::query()->findOrFail($projectId);

        $feature = $project->features()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => isset($validated['status']) ? FeatureStatus::from($validated['status']) : FeatureStatus::Proposed,
        ]);

        return Response::json([
            'id' => $feature->id,
            'project_id' => $feature->project_id,
            'name' => $feature->name,
            'description' => $feature->description,
            'notes' => $feature->notes,
            'status' => $feature->status?->value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        $statuses = array_column(FeatureStatus::cases(), 'value');

        return [
            'project_id' => $schema->integer()
                ->description('Project to create the feature in. Defaults to the user’s current project.'),
            'name' => $schema->string()
                ->description('Feature name. Required.')
                ->required(),
            'description' => $schema->string()
                ->description('Product-owner framing of the capability — what users get and why it matters. Not implementation detail (no schemas, classes, file paths). Markdown supported.'),
            'notes' => $schema->string()
                ->description('Anything that doesn’t fit the product-owner framing — caveats, links, scope reminders, references. Implementation steps still belong in a plan. Markdown supported.'),
            'status' => $schema->string()
                ->description('Feature status. Defaults to "proposed". One of: '.implode(', ', $statuses)),
        ];
    }
}
