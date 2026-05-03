<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Task;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: update-task
 */
#[Description('Update a single task. Any of: name, description (markdown), status, acceptance_criterion_id, scenario_id, depends_on_positions (replaces existing). Editing structural fields on an Approved story resets it to PendingApproval.')]
class UpdateTaskTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-task';

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
            'task_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'acceptance_criterion_id' => ['nullable', 'integer'],
            'scenario_id' => ['nullable', 'integer'],
            'depends_on_positions' => ['nullable', 'array'],
            'depends_on_positions.*' => ['integer', 'min:1'],
        ]);

        $task = Task::query()->with('story.feature', 'story.acceptanceCriteria:id,story_id', 'story.scenarios:id,story_id', 'story.tasks:id,story_id,plan_id,position')->find($validated['task_id']);
        if (! $task) {
            return Response::error('Task not found.');
        }

        if (! $this->canAccessProject($user, (int) $task->story->feature->project_id)) {
            return Response::error('Task not accessible.');
        }

        $structuralChange = false;

        DB::transaction(function () use ($task, $validated, &$structuralChange) {
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
                if ($acId !== null && ! $task->story->acceptanceCriteria->contains('id', $acId)) {
                    throw new \RuntimeException("acceptance_criterion_id {$acId} does not belong to this story.");
                }
                $updates['acceptance_criterion_id'] = $acId;
                $structuralChange = true;
            }
            if (array_key_exists('scenario_id', $validated)) {
                $scenarioId = $validated['scenario_id'];
                if ($scenarioId !== null && ! $task->story->scenarios->contains('id', $scenarioId)) {
                    throw new \RuntimeException("scenario_id {$scenarioId} does not belong to this story.");
                }
                $updates['scenario_id'] = $scenarioId;
                $structuralChange = true;
            }

            if (! empty($updates)) {
                $task->forceFill($updates)->save();
            }

            if (array_key_exists('depends_on_positions', $validated) && is_array($validated['depends_on_positions'])) {
                $byPosition = $task->story->tasks->where('plan_id', $task->plan_id)->keyBy('position');
                $depIds = [];
                foreach ($validated['depends_on_positions'] as $pos) {
                    if ((int) $pos === (int) $task->position) {
                        continue;
                    }
                    $dep = $byPosition[$pos] ?? null;
                    if ($dep) {
                        $depIds[] = $dep->id;
                    }
                }
                $task->dependencies()->sync($depIds);
                $structuralChange = true;
            }
        });

        if ($structuralChange) {
            if ($task->story->status === StoryStatus::Approved) {
                $task->story->silentlyForceFill([
                    'status' => StoryStatus::PendingApproval->value,
                    'revision' => ($task->story->revision ?? 1) + 1,
                ]);
            } elseif ($task->story->status === StoryStatus::ChangesRequested) {
                $task->story->silentlyForceFill(['status' => StoryStatus::PendingApproval->value]);
            }

            app(ApprovalService::class)->recompute($task->story->fresh());
        }

        $task->refresh()->load('dependencies:id,position', 'acceptanceCriterion:id,position,statement', 'scenario:id,position,name');

        return Response::json([
            'id' => $task->id,
            'story_id' => $task->story_id,
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
