<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: list-runs
 */
#[Description('List recent agent runs filtered by story, task, or subtask. Newest first.')]
class ListRunsTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-runs';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $storyId = $request->integer('story_id') ?: null;
        $taskId = $request->integer('task_id') ?: null;
        $subtaskId = $request->integer('subtask_id') ?: null;
        $limit = min(max((int) ($request->integer('limit') ?: 25), 1), 200);

        if (! $storyId && ! $taskId && ! $subtaskId) {
            return Response::error('Provide at least one of: story_id, task_id, subtask_id.');
        }

        $query = AgentRun::query()->latest('id')->limit($limit);

        if ($subtaskId) {
            $subtask = Subtask::query()->with('task.story.feature')->find($subtaskId);
            if (! $subtask) {
                return Response::error('Subtask not found.');
            }
            $projectId = $subtask->task?->story?->feature?->project_id;
            if (! $projectId || ! $this->canAccessProject($user, (int) $projectId)) {
                return Response::error('Subtask not accessible.');
            }
            $query->where('runnable_type', Subtask::class)->where('runnable_id', $subtask->id);
        } elseif ($taskId) {
            $task = Task::query()->with('story.feature', 'subtasks:id,task_id')->find($taskId);
            if (! $task) {
                return Response::error('Task not found.');
            }
            if (! $this->canAccessProject($user, (int) $task->story->feature->project_id)) {
                return Response::error('Task not accessible.');
            }
            $subtaskIds = $task->subtasks->pluck('id')->all();
            $query->where(function ($q) use ($task, $subtaskIds) {
                $q->where(function ($q) use ($task) {
                    $q->where('runnable_type', Task::class)->where('runnable_id', $task->id);
                });
                if (! empty($subtaskIds)) {
                    $q->orWhere(function ($q) use ($subtaskIds) {
                        $q->where('runnable_type', Subtask::class)->whereIn('runnable_id', $subtaskIds);
                    });
                }
            });
        } else {
            $story = Story::query()->with('feature', 'tasks:id,story_id,plan_id')->find($storyId);
            if (! $story) {
                return Response::error('Story not found.');
            }
            if (! $this->canAccessProject($user, (int) $story->feature->project_id)) {
                return Response::error('Story not accessible.');
            }
            $taskIds = $story->tasks->pluck('id')->all();
            $subtaskIds = ! empty($taskIds) ? Subtask::query()->whereIn('task_id', $taskIds)->pluck('id')->all() : [];
            $query->where(function ($q) use ($story, $taskIds, $subtaskIds) {
                $q->where(function ($q) use ($story) {
                    $q->where('runnable_type', Story::class)->where('runnable_id', $story->id);
                });
                if (! empty($taskIds)) {
                    $q->orWhere(function ($q) use ($taskIds) {
                        $q->where('runnable_type', Task::class)->whereIn('runnable_id', $taskIds);
                    });
                }
                if (! empty($subtaskIds)) {
                    $q->orWhere(function ($q) use ($subtaskIds) {
                        $q->where('runnable_type', Subtask::class)->whereIn('runnable_id', $subtaskIds);
                    });
                }
            });
        }

        $runs = $query->get([
            'id', 'runnable_type', 'runnable_id', 'repo_id', 'working_branch',
            'status', 'agent_name', 'model_id', 'started_at', 'finished_at',
            'tokens_input', 'tokens_output', 'error_message',
        ]);

        return Response::json([
            'count' => $runs->count(),
            'runs' => $runs->map(fn (AgentRun $r) => [
                'id' => $r->id,
                'runnable' => [
                    'type' => class_basename($r->runnable_type),
                    'id' => $r->runnable_id,
                ],
                'repo_id' => $r->repo_id,
                'working_branch' => $r->working_branch,
                'status' => $r->status?->value,
                'agent_name' => $r->agent_name,
                'model_id' => $r->model_id,
                'started_at' => $r->started_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
                'tokens_input' => $r->tokens_input,
                'tokens_output' => $r->tokens_output,
                'error_message' => $r->error_message,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Limit to runs under this story.'),
            'task_id' => $schema->integer()->description('Limit to runs under this task (its task-row + subtask runs).'),
            'subtask_id' => $schema->integer()->description('Limit to runs for this subtask.'),
            'limit' => $schema->integer()->description('Max number of runs to return (1–200, default 25).'),
        ];
    }
}
