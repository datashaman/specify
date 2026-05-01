<?php

namespace App\Services;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Replaces the plan (task list) of a Story atomically.
 *
 * One transaction: delete prior tasks, create new tasks + subtasks, wire
 * task-level dependencies, and reset approval (Approved → PendingApproval +
 * revision bump; ChangesRequested → PendingApproval). After commit, the
 * approval policy is recomputed so an auto-approve policy can promote the
 * story back to Approved without human input.
 *
 * Both human edits (SetTasksTool) and agent output (GenerateTasksJob) flow
 * through this single seam so the "plan replacement resets approval"
 * invariant lives in one place.
 */
class PlanWriter
{
    public function __construct(public ApprovalService $approvals) {}

    /**
     * @param  list<array{
     *     position: int,
     *     name: string,
     *     description?: ?string,
     *     acceptance_criterion_id?: ?int,
     *     depends_on_positions?: list<int>,
     *     subtasks: list<array{position: int, name: string, description?: ?string}>,
     * }>  $tasks
     * @return array{task_count: int, subtask_count: int}
     */
    public function replacePlan(Story $story, array $tasks): array
    {
        $this->validate($story, $tasks);

        $result = DB::transaction(function () use ($story, $tasks) {
            $story->tasks()->delete();

            $tasksByPosition = [];

            foreach ($tasks as $taskData) {
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

            foreach ($tasks as $taskData) {
                foreach ($taskData['depends_on_positions'] ?? [] as $depPosition) {
                    if (! isset($tasksByPosition[$taskData['position']], $tasksByPosition[$depPosition])) {
                        continue;
                    }
                    $tasksByPosition[$taskData['position']]->addDependency($tasksByPosition[$depPosition]);
                }
            }

            if ($story->status === StoryStatus::Approved) {
                $story->silentlyForceFill([
                    'status' => StoryStatus::PendingApproval->value,
                    'revision' => ($story->revision ?? 1) + 1,
                ]);
            } elseif ($story->status === StoryStatus::ChangesRequested) {
                $story->silentlyForceFill(['status' => StoryStatus::PendingApproval->value]);
            }

            return [
                'task_count' => count($tasksByPosition),
                'subtask_count' => Subtask::whereIn('task_id', collect($tasksByPosition)->map->getKey()->all())->count(),
            ];
        });

        $this->approvals->recompute($story->fresh());

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function validate(Story $story, array $tasks): void
    {
        if ($tasks === []) {
            throw new InvalidArgumentException('Plan must contain at least one task.');
        }

        $taskPositions = array_column($tasks, 'position');
        if (count($taskPositions) !== count(array_unique($taskPositions))) {
            throw new InvalidArgumentException('Task positions must be unique within a story.');
        }

        $allowedAcIds = $story->acceptanceCriteria()->pluck('id')->all();
        $usedAcIds = [];

        foreach ($tasks as $taskData) {
            if (! empty($taskData['acceptance_criterion_id'])) {
                if (! in_array($taskData['acceptance_criterion_id'], $allowedAcIds, true)) {
                    throw new InvalidArgumentException(
                        "acceptance_criterion_id {$taskData['acceptance_criterion_id']} does not belong to this story."
                    );
                }
                $usedAcIds[] = $taskData['acceptance_criterion_id'];
            }

            if (empty($taskData['subtasks'])) {
                throw new InvalidArgumentException(
                    "Task at position {$taskData['position']} must have at least one subtask."
                );
            }

            $subPositions = array_column($taskData['subtasks'], 'position');
            if (count($subPositions) !== count(array_unique($subPositions))) {
                throw new InvalidArgumentException(
                    "Subtask positions must be unique within task at position {$taskData['position']}."
                );
            }
        }

        if (count($usedAcIds) !== count(array_unique($usedAcIds))) {
            throw new InvalidArgumentException('Each acceptance_criterion_id may only be linked to one task.');
        }
    }
}
