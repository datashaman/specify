<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Mcp\Auth;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Replace the entire task list for a story in one transaction. Each task gets 1+ ordered subtasks; tasks may declare task-level dependencies via positions; each task may link to one acceptance_criterion_id belonging to the story. If the story is currently Approved, this resets it to PendingApproval. Markdown is supported in description fields.')]
class SetTasksTool extends Tool
{
    protected string $name = 'set-tasks';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.position' => ['required', 'integer', 'min:0'],
            'tasks.*.name' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.acceptance_criterion_id' => ['nullable', 'integer'],
            'tasks.*.depends_on_positions' => ['nullable', 'array'],
            'tasks.*.depends_on_positions.*' => ['integer', 'min:0'],
            'tasks.*.subtasks' => ['required', 'array', 'min:1'],
            'tasks.*.subtasks.*.position' => ['required', 'integer', 'min:0'],
            'tasks.*.subtasks.*.name' => ['required', 'string', 'max:255'],
            'tasks.*.subtasks.*.description' => ['nullable', 'string'],
        ]);

        $story = Story::query()->with('feature', 'acceptanceCriteria:id,story_id')->find($validated['story_id']);
        if (! $story) {
            return Response::error('Story not found.');
        }

        if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Story not accessible.');
        }

        $taskPositions = array_column($validated['tasks'], 'position');
        if (count($taskPositions) !== count(array_unique($taskPositions))) {
            return Response::error('Task positions must be unique within a story.');
        }

        $allowedAcIds = $story->acceptanceCriteria->pluck('id')->all();
        foreach ($validated['tasks'] as $t) {
            if (! empty($t['acceptance_criterion_id']) && ! in_array($t['acceptance_criterion_id'], $allowedAcIds, true)) {
                return Response::error("acceptance_criterion_id {$t['acceptance_criterion_id']} does not belong to this story.");
            }
            $subPositions = array_column($t['subtasks'], 'position');
            if (count($subPositions) !== count(array_unique($subPositions))) {
                return Response::error("Subtask positions must be unique within task at position {$t['position']}.");
            }
        }

        $usedAcIds = array_filter(array_column($validated['tasks'], 'acceptance_criterion_id'));
        if (count($usedAcIds) !== count(array_unique($usedAcIds))) {
            return Response::error('Each acceptance_criterion_id may only be linked to one task.');
        }

        $result = DB::transaction(function () use ($story, $validated) {
            $story->tasks()->delete();

            $tasksByPosition = [];

            foreach ($validated['tasks'] as $taskData) {
                $task = Task::create([
                    'story_id' => $story->getKey(),
                    'acceptance_criterion_id' => $taskData['acceptance_criterion_id'] ?? null,
                    'position' => $taskData['position'],
                    'name' => $taskData['name'],
                    'description' => $taskData['description'] ?? null,
                ]);

                foreach ($taskData['subtasks'] as $subtaskData) {
                    Subtask::create([
                        'task_id' => $task->getKey(),
                        'position' => $subtaskData['position'],
                        'name' => $subtaskData['name'],
                        'description' => $subtaskData['description'] ?? null,
                    ]);
                }

                $tasksByPosition[$taskData['position']] = $task;
            }

            foreach ($validated['tasks'] as $taskData) {
                foreach ($taskData['depends_on_positions'] ?? [] as $depPosition) {
                    if (! isset($tasksByPosition[$taskData['position']], $tasksByPosition[$depPosition])) {
                        continue;
                    }
                    $tasksByPosition[$taskData['position']]->addDependency($tasksByPosition[$depPosition]);
                }
            }

            if ($story->status === StoryStatus::Approved) {
                $story->forceFill([
                    'status' => StoryStatus::PendingApproval->value,
                    'revision' => ($story->revision ?? 1) + 1,
                ])->save();
            } elseif ($story->status === StoryStatus::ChangesRequested) {
                $story->forceFill(['status' => StoryStatus::PendingApproval->value])->save();
            }

            return [
                'task_count' => count($tasksByPosition),
                'subtask_count' => Subtask::whereIn('task_id', collect($tasksByPosition)->map->getKey()->all())->count(),
            ];
        });

        return Response::json([
            'story_id' => $story->id,
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
            'story_id' => $schema->integer()->description('Story whose task list to replace.')->required(),
            'tasks' => $schema->array()
                ->description('Ordered list. Each item: {position:int (>=0), name:string, description?:string (markdown), acceptance_criterion_id?:int, depends_on_positions?:int[], subtasks: [{position:int (>=0), name:string, description?:string (markdown)}]}. Each task must have at least one subtask. Position values must be unique within their parent.')
                ->required(),
        ];
    }
}
