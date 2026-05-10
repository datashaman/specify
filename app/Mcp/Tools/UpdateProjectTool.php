<?php

namespace App\Mcp\Tools;

use App\Enums\ProjectStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: update-project
 */
#[Description('Update an existing project’s name, description, or status. Caller must have approve rights in the project.')]
class UpdateProjectTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-project';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(ProjectStatus::cases(), 'value'))],
        ]);

        $project = $this->resolveAccessibleProject($validated['project_id'], $user);
        if ($project instanceof Response) {
            return $project;
        }

        if (! $user->canApproveInProject($project)) {
            return Response::error('You must have approve rights in this project to update it.');
        }

        $changes = array_filter([
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => isset($validated['status']) ? ProjectStatus::from($validated['status']) : null,
        ], fn ($v) => $v !== null);

        if (! $changes) {
            return Response::error('Provide at least one of: name, description, status.');
        }

        $project->fill($changes)->save();

        return Response::json([
            'id' => $project->id,
            'team_id' => $project->team_id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status?->value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        $statuses = array_column(ProjectStatus::cases(), 'value');

        return [
            'project_id' => $schema->integer()->description('Project to update.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description. Markdown supported.'),
            'status' => $schema->string()->description('New status. One of: '.implode(', ', $statuses)),
        ];
    }
}
