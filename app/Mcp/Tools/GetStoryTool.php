<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\Auth;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get a story in detail, including its acceptance criteria and task progress counts.')]
class GetStoryTool extends Tool
{
    protected string $name = 'get-story';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $storyId = $request->integer('story_id');
        if (! $storyId) {
            return Response::error('story_id is required.');
        }

        $story = Story::query()->with(['feature', 'acceptanceCriteria.task:id,acceptance_criterion_id,status', 'tasks:id,story_id,status'])->find($storyId);
        if (! $story) {
            return Response::error('Story not found.');
        }

        if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Story not accessible.');
        }

        $tasksTotal = $story->tasks->count();
        $tasksDone = $story->tasks->filter(fn ($t) => $t->status === TaskStatus::Done)->count();

        return Response::json([
            'id' => $story->id,
            'feature_id' => $story->feature_id,
            'feature_name' => $story->feature->name,
            'project_id' => $story->feature->project_id,
            'name' => $story->name,
            'description' => $story->description,
            'notes' => $story->notes,
            'status' => $story->status?->value,
            'revision' => $story->revision,
            'tasks_count' => $tasksTotal,
            'tasks_done_count' => $tasksDone,
            'acceptance_criteria' => $story->acceptanceCriteria->map(fn ($ac) => [
                'id' => $ac->id,
                'position' => $ac->position,
                'criterion' => $ac->criterion,
                'met' => (bool) $ac->met,
                'task_id' => $ac->task?->id,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()
                ->description('Story to fetch.')
                ->required(),
        ];
    }
}
