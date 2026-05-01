<?php

namespace App\Mcp\Tools;

use App\Enums\FeatureStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: update-feature
 */
#[Description('Update an existing feature’s name, description, or status.')]
class UpdateFeatureTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-feature';

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
            'feature_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(FeatureStatus::cases(), 'value'))],
        ]);

        $feature = $this->resolveAccessibleFeature($validated['feature_id'], $user);
        if ($feature instanceof Response) {
            return $feature;
        }

        $changes = array_filter([
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => isset($validated['status']) ? FeatureStatus::from($validated['status']) : null,
        ], fn ($v) => $v !== null);

        if (! $changes) {
            return Response::error('Provide at least one of: name, description, notes, status.');
        }

        $feature->fill($changes)->save();

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
            'feature_id' => $schema->integer()
                ->description('Feature to update.')
                ->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('Product-owner framing — what users get, why it matters. No implementation detail. Markdown supported.'),
            'notes' => $schema->string()->description('Caveats, links, scope reminders. Markdown supported.'),
            'status' => $schema->string()
                ->description('New status. One of: '.implode(', ', $statuses)),
        ];
    }
}
