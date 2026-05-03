<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: get-task
 */
#[Description('Get a task in detail, including its subtasks (positions, statuses) and dependency positions.')]
class GetTaskTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'get-task';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $taskId = $request->integer('task_id');
        if (! $taskId) {
            return Response::error('task_id is required.');
        }

        $task = Task::query()
            ->with(['story.feature', 'plan', 'acceptanceCriterion', 'scenario', 'subtasks', 'dependencies:id,position'])
            ->find($taskId);

        if (! $task) {
            return Response::error('Task not found.');
        }

        if (! $this->canAccessProject($user, (int) $task->story->feature->project_id)) {
            return Response::error('Task not accessible.');
        }

        return Response::json([
            'id' => $task->id,
            'story_id' => $task->story_id,
            'plan_id' => $task->plan_id,
            'position' => $task->position,
            'name' => $task->name,
            'description' => $task->description,
            'status' => $task->status?->value,
            'acceptance_criterion' => $task->acceptanceCriterion ? [
                'id' => $task->acceptanceCriterion->id,
                'position' => $task->acceptanceCriterion->position,
                'statement' => $task->acceptanceCriterion->statement,
            ] : null,
            'scenario' => $task->scenario ? [
                'id' => $task->scenario->id,
                'position' => $task->scenario->position,
                'name' => $task->scenario->name,
            ] : null,
            'depends_on_positions' => $task->dependencies->pluck('position')->all(),
            'subtasks' => $task->subtasks->sortBy('position')->values()->map(fn ($s) => [
                'id' => $s->id,
                'position' => $s->position,
                'name' => $s->name,
                'description' => $s->description,
                'status' => $s->status?->value,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Task to fetch.')->required(),
        ];
    }
}
