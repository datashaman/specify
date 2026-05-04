<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Task;
use App\Services\Tasks\TaskDependencyGraph;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: update-task
 */
#[Description('Update a single task. Any of: name, description (markdown), status, acceptance_criterion_id, scenario_id, depends_on_positions (replaces existing). Editing structural fields on the current plan reopens plan approval.')]
class UpdateTaskTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-task';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request, TaskDependencyGraph $dependencies): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'acceptance_criterion_id' => ['nullable', 'integer'],
            'scenario_id' => ['nullable', 'integer'],
            'depends_on_positions' => ['nullable', 'array'],
            'depends_on_positions.*' => ['integer', 'min:1'],
        ]);

        $task = Task::query()->with('plan.story.feature', 'plan.story.acceptanceCriteria:id,story_id', 'plan.story.scenarios:id,story_id')->find($validated['task_id']);
        if (! $task) {
            return Response::error('Task not found.');
        }

        if (! $this->canAccessProject($user, (int) $task->plan->story->feature->project_id)) {
            return Response::error('Task not accessible.');
        }

        $structuralChange = false;

        try {
            DB::transaction(function () use ($task, $validated, $dependencies, &$structuralChange) {
                $updates = [];

                if (array_key_exists('name', $validated) && $validated['name'] !== null) {
                    $updates['name'] = $validated['name'];
                    $structuralChange = true;
                }
                if (array_key_exists('description', $validated)) {
                    $updates['description'] = $validated['description'];
                    $structuralChange = true;
                }
                if (array_key_exists('status', $validated) && $validated['status'] !== null) {
                    $status = TaskStatus::tryFrom($validated['status']);
                    if ($status === null) {
                        throw new \RuntimeException("Unknown status '{$validated['status']}'.");
                    }
                    $updates['status'] = $status->value;
                }
                if (array_key_exists('acceptance_criterion_id', $validated)) {
                    $acId = $validated['acceptance_criterion_id'];
                    if ($acId !== null && ! $task->plan->story->acceptanceCriteria->contains('id', $acId)) {
                        throw new \RuntimeException("acceptance_criterion_id {$acId} does not belong to this story.");
                    }
                    $updates['acceptance_criterion_id'] = $acId;
                    $structuralChange = true;
                }
                if (array_key_exists('scenario_id', $validated)) {
                    $scenarioId = $validated['scenario_id'];
                    if ($scenarioId !== null && ! $task->plan->story->scenarios->contains('id', $scenarioId)) {
                        throw new \RuntimeException("scenario_id {$scenarioId} does not belong to this story.");
                    }
                    $updates['scenario_id'] = $scenarioId;
                    $structuralChange = true;
                }

                if (! empty($updates)) {
                    $task->forceFill($updates)->save();
                }

                if (array_key_exists('depends_on_positions', $validated) && is_array($validated['depends_on_positions'])) {
                    $byPosition = Task::query()
                        ->where('plan_id', $task->plan_id)
                        ->get(['id', 'plan_id', 'position'])
                        ->keyBy('position');
                    $replacements = [];
                    foreach ($validated['depends_on_positions'] as $pos) {
                        $dep = $byPosition[$pos] ?? null;
                        if ($dep) {
                            $replacements[] = $dep;
                        }
                    }
                    $dependencies->replaceDependencies($task, $replacements);
                    $structuralChange = true;
                }
            });
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        if ($structuralChange && $task->plan) {
            $task->plan->reopenForApproval();
        }

        $task->refresh()->load('dependencies:id,position', 'acceptanceCriterion:id,position,statement', 'scenario:id,position,name');

        return Response::json([
            'id' => $task->id,
            'story_id' => $task->plan->story_id,
            'plan_id' => $task->plan_id,
            'position' => $task->position,
            'name' => $task->name,
            'description' => $task->description,
            'status' => $task->status?->value,
            'acceptance_criterion_id' => $task->acceptance_criterion_id,
            'scenario_id' => $task->scenario_id,
            'depends_on_positions' => $task->dependencies->pluck('position')->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Task to update.')->required(),
            'name' => $schema->string()->description('New task name.'),
            'description' => $schema->string()->description('New task description (markdown supported).'),
            'status' => $schema->string()->description('Status: pending|in_progress|done|blocked.'),
            'acceptance_criterion_id' => $schema->integer()->description('Acceptance criterion this task fulfils. Must belong to the same story.'),
            'scenario_id' => $schema->integer()->description('Scenario this task supports. Must belong to the same story.'),
            'depends_on_positions' => $schema->array()->description('Replace dependencies with the tasks at these positions in the same plan.'),
        ];
    }
}
