<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\PlanWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: set-tasks
 */
#[Description('Replace the story\'s current implementation Plan in one transaction by writing a fresh ordered Task/Subtask breakdown. Each Task belongs to the new Plan, may link to an optional acceptance_criterion_id and/or scenario_id from the same Story, and may declare task-level dependencies via positions. Replacing the Plan reopens Plan approval. Markdown is supported in description fields.')]
class SetTasksTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'set-tasks';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request, PlanWriter $planWriter): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.position' => ['required', 'integer', 'min:1'],
            'tasks.*.name' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.acceptance_criterion_id' => ['nullable', 'integer'],
            'tasks.*.scenario_id' => ['nullable', 'integer'],
            'tasks.*.depends_on_positions' => ['nullable', 'array'],
            'tasks.*.depends_on_positions.*' => ['integer', 'min:1'],
            'tasks.*.subtasks' => ['required', 'array', 'min:1'],
            'tasks.*.subtasks.*.position' => ['required', 'integer', 'min:1'],
            'tasks.*.subtasks.*.name' => ['required', 'string', 'max:255'],
            'tasks.*.subtasks.*.description' => ['nullable', 'string'],
        ]);

        $story = $this->resolveAccessibleStory($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        try {
            $result = $planWriter->replacePlan($story, $validated['tasks']);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'story_id' => $story->id,
            'plan_id' => $result['plan_id'],
            'task_count' => $result['task_count'],
            'subtask_count' => $result['subtask_count'],
            'story_status' => $story->fresh()->status?->value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story whose current implementation Plan should be replaced.')->required(),
            'tasks' => $schema->array()
                ->description('Ordered Tasks for the fresh current Plan. Each item: {position:int (>=1), name:string, description?:string (markdown), acceptance_criterion_id?:int, scenario_id?:int, depends_on_positions?:int[], subtasks: [{position:int (>=1), name:string, description?:string (markdown)}]}. Criterion/scenario links are optional; use them only when a Task directly supports that product artifact. Each Task must have at least one Subtask. Position values must be unique within their parent.')
                ->required(),
        ];
    }
}
