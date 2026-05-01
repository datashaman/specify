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
 * MCP tool: get-run
 */
#[Description('Get an agent run in detail (input/output/diff). Diff is omitted by default — pass include_diff=true to fetch.')]
class GetRunTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'get-run';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $runId = $request->integer('run_id');
        if (! $runId) {
            return Response::error('run_id is required.');
        }

        $includeDiff = (bool) $request->boolean('include_diff');

        $run = AgentRun::query()->find($runId);
        if (! $run) {
            return Response::error('Run not found.');
        }

        $projectId = $this->resolveProjectId($run);
        if ($projectId === null || ! $this->canAccessProject($user, $projectId)) {
            return Response::error('Run not accessible.');
        }

        return Response::json([
            'id' => $run->id,
            'runnable' => [
                'type' => class_basename($run->runnable_type),
                'id' => $run->runnable_id,
            ],
            'repo_id' => $run->repo_id,
            'working_branch' => $run->working_branch,
            'status' => $run->status?->value,
            'agent_name' => $run->agent_name,
            'model_id' => $run->model_id,
            'input' => $run->input,
            'output' => $run->output,
            'diff' => $includeDiff ? $run->diff : null,
            'error_message' => $run->error_message,
            'tokens_input' => $run->tokens_input,
            'tokens_output' => $run->tokens_output,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ]);
    }

    private function resolveProjectId(AgentRun $run): ?int
    {
        return match ($run->runnable_type) {
            Subtask::class => Subtask::query()
                ->with('task.story.feature:id,project_id')
                ->find($run->runnable_id)?->task?->story?->feature?->project_id,
            Task::class => Task::query()
                ->with('story.feature:id,project_id')
                ->find($run->runnable_id)?->story?->feature?->project_id,
            Story::class => Story::query()
                ->with('feature:id,project_id')
                ->find($run->runnable_id)?->feature?->project_id,
            default => null,
        };
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->integer()->description('Run to fetch.')->required(),
            'include_diff' => $schema->boolean()->description('Include the full diff blob. Defaults to false.'),
        ];
    }
}
