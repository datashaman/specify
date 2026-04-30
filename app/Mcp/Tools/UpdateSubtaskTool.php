<?php

namespace App\Mcp\Tools;

use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Mcp\Auth;
use App\Models\Subtask;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a single subtask. Any of: name, description (markdown), status, position. Editing structural fields (name/description/position) on an Approved story resets it to PendingApproval.')]
class UpdateSubtaskTool extends Tool
{
    protected string $name = 'update-subtask';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'subtask_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $subtask = Subtask::query()->with('task.story.feature')->find($validated['subtask_id']);
        if (! $subtask) {
            return Response::error('Subtask not found.');
        }

        $story = $subtask->task?->story;
        if (! $story) {
            return Response::error('Subtask is orphaned.');
        }

        if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Subtask not accessible.');
        }

        $structuralChange = false;
        $updates = [];

        if (array_key_exists('name', $validated) && $validated['name'] !== null) {
            $updates['name'] = $validated['name'];
            $structuralChange = true;
        }
        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
            $structuralChange = true;
        }
        if (array_key_exists('position', $validated) && $validated['position'] !== null) {
            $updates['position'] = $validated['position'];
            $structuralChange = true;
        }
        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $status = TaskStatus::tryFrom($validated['status']);
            if ($status === null) {
                return Response::error("Unknown status '{$validated['status']}'.");
            }
            $updates['status'] = $status->value;
        }

        if (! empty($updates)) {
            $subtask->forceFill($updates)->save();
        }

        if ($structuralChange) {
            if ($story->status === StoryStatus::Approved) {
                $story->silentlyForceFill([
                    'status' => StoryStatus::PendingApproval->value,
                    'revision' => ($story->revision ?? 1) + 1,
                ]);
            } elseif ($story->status === StoryStatus::ChangesRequested) {
                $story->silentlyForceFill(['status' => StoryStatus::PendingApproval->value]);
            }

            app(ApprovalService::class)->recompute($story->fresh());
        }

        $subtask->refresh();

        return Response::json([
            'id' => $subtask->id,
            'task_id' => $subtask->task_id,
            'position' => $subtask->position,
            'name' => $subtask->name,
            'description' => $subtask->description,
            'status' => $subtask->status?->value,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'subtask_id' => $schema->integer()->description('Subtask to update.')->required(),
            'name' => $schema->string()->description('New subtask name.'),
            'description' => $schema->string()->description('New subtask description (markdown supported).'),
            'status' => $schema->string()->description('Status: pending|in_progress|done|blocked.'),
            'position' => $schema->integer()->description('New ordering position within the parent task (1-based).'),
        ];
    }
}
