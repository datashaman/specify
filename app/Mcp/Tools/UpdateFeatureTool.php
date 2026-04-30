<?php

namespace App\Mcp\Tools;

use App\Enums\FeatureStatus;
use App\Mcp\Auth;
use App\Models\Feature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an existing feature’s name, description, or status.')]
class UpdateFeatureTool extends Tool
{
    protected string $name = 'update-feature';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'feature_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(FeatureStatus::cases(), 'value'))],
        ]);

        $feature = Feature::query()->find($validated['feature_id']);
        if (! $feature) {
            return Response::error('Feature not found.');
        }

        if (! in_array($feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Feature not accessible.');
        }

        $changes = array_filter([
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => isset($validated['status']) ? FeatureStatus::from($validated['status']) : null,
        ], fn ($v) => $v !== null);

        if (! $changes) {
            return Response::error('Provide at least one of: name, description, status.');
        }

        $feature->fill($changes)->save();

        return Response::json([
            'id' => $feature->id,
            'project_id' => $feature->project_id,
            'name' => $feature->name,
            'description' => $feature->description,
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
            'feature_id' => $schema->integer()
                ->description('Feature to update.')
                ->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description.'),
            'status' => $schema->string()
                ->description('New status. One of: '.implode(', ', $statuses)),
        ];
    }
}
