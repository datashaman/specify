<?php

namespace App\Mcp\Tools;

use App\Services\ExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * Deprecated MCP tool: generate-plan
 *
 * Forwards to `GenerateTasksTool` (slug `generate-tasks`) and logs a
 * deprecation warning. ADR-0002 retired the Plan model; the slug was kept
 * here for caller continuity. This shim will be removed in a future minor
 * release once external callers have migrated.
 */
#[Description('DEPRECATED — use generate-tasks instead. Generates the task list (tasks + subtasks) for an Approved story. Forwards to generate-tasks; the slug will be removed in a future release.')]
class GeneratePlanTool extends Tool
{
    protected string $name = 'generate-plan';

    /**
     * Handle the MCP tool invocation by delegating to the renamed tool.
     */
    public function handle(Request $request, ExecutionService $execution, GenerateTasksTool $successor): Response
    {
        Log::warning('specify.mcp.deprecated_tool_called', [
            'deprecated_slug' => 'generate-plan',
            'use_instead' => 'generate-tasks',
        ]);

        return $successor->handle($request, $execution);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Approved story to generate tasks for. Must have no existing tasks.')->required(),
        ];
    }
}
