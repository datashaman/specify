<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\AgentRun;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List recent agent runs filtered by story, plan, or task. Newest first.')]
class ListRunsTool extends Tool
{
    protected string $name = 'list-runs';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $storyId = $request->integer('story_id') ?: null;
        $planId = $request->integer('plan_id') ?: null;
        $taskId = $request->integer('task_id') ?: null;
        $limit = min(max((int) ($request->integer('limit') ?: 25), 1), 200);

        if (! $storyId && ! $planId && ! $taskId) {
            return Response::error('Provide at least one of: story_id, plan_id, task_id.');
        }

        $query = AgentRun::query()->latest('id')->limit($limit);

        if ($taskId) {
            $task = Task::query()->with('plan.story.feature')->find($taskId);
            if (! $task) {
                return Response::error('Task not found.');
            }
            if (! in_array($task->plan->story->feature->project_id, $user->accessibleProjectIds(), true)) {
                return Response::error('Task not accessible.');
            }
            $query->where('runnable_type', Task::class)->where('runnable_id', $task->id);
        } elseif ($planId) {
            $plan = Plan::query()->with('story.feature', 'tasks:id,plan_id')->find($planId);
            if (! $plan) {
                return Response::error('Plan not found.');
            }
            if (! in_array($plan->story->feature->project_id, $user->accessibleProjectIds(), true)) {
                return Response::error('Plan not accessible.');
            }
            $taskIds = $plan->tasks->pluck('id')->all();
            $query->where(function ($q) use ($plan, $taskIds) {
                $q->where(function ($q) use ($plan) {
                    $q->where('runnable_type', Plan::class)->where('runnable_id', $plan->id);
                });
                if (! empty($taskIds)) {
                    $q->orWhere(function ($q) use ($taskIds) {
                        $q->where('runnable_type', Task::class)->whereIn('runnable_id', $taskIds);
                    });
                }
            });
        } else {
            $story = Story::query()->with('feature', 'plans:id,story_id')->find($storyId);
            if (! $story) {
                return Response::error('Story not found.');
            }
            if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
                return Response::error('Story not accessible.');
            }
            $planIds = $story->plans->pluck('id')->all();
            $taskIds = Task::query()->whereIn('plan_id', $planIds)->pluck('id')->all();
            $query->where(function ($q) use ($story, $planIds, $taskIds) {
                $q->where(function ($q) use ($story) {
                    $q->where('runnable_type', Story::class)->where('runnable_id', $story->id);
                });
                if (! empty($planIds)) {
                    $q->orWhere(function ($q) use ($planIds) {
                        $q->where('runnable_type', Plan::class)->whereIn('runnable_id', $planIds);
                    });
                }
                if (! empty($taskIds)) {
                    $q->orWhere(function ($q) use ($taskIds) {
                        $q->where('runnable_type', Task::class)->whereIn('runnable_id', $taskIds);
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
            'plan_id' => $schema->integer()->description('Limit to runs under this plan.'),
            'task_id' => $schema->integer()->description('Limit to runs for this task.'),
            'limit' => $schema->integer()->description('Max number of runs to return (1–200, default 25).'),
        ];
    }
}
