<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: list-tasks
 */
#[Description('List the tasks attached to a story, in position order. Each entry includes subtask counts and the linked acceptance criterion text.')]
class ListTasksTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-tasks';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $storyId = $request->integer('story_id');
        if (! $storyId) {
            return Response::error('story_id is required.');
        }

        $story = $this->resolveAccessibleStory($storyId, $user);
        if ($story instanceof Response) {
            return $story;
        }

        $tasks = $story->tasks()
            ->with(['acceptanceCriterion:id,position,criterion', 'subtasks:id,task_id,status', 'dependencies:id,position'])
            ->orderBy('position')
            ->get();

        return Response::json([
            'story_id' => $story->id,
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'position' => $task->position,
                'name' => $task->name,
                'description' => $task->description,
                'status' => $task->status?->value,
                'acceptance_criterion' => $task->acceptanceCriterion ? [
                    'id' => $task->acceptanceCriterion->id,
                    'position' => $task->acceptanceCriterion->position,
                    'criterion' => $task->acceptanceCriterion->criterion,
                ] : null,
                'depends_on_positions' => $task->dependencies->pluck('position')->all(),
                'subtasks_total' => $task->subtasks->count(),
                'subtasks_done' => $task->subtasks->filter(fn ($s) => $s->status === TaskStatus::Done)->count(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story whose tasks to list.')->required(),
        ];
    }
}
