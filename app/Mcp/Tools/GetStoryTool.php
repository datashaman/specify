<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: get-story
 */
#[Description('Get a story in detail, including its acceptance criteria and task progress counts.')]
class GetStoryTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'get-story';

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
        $story->load(['acceptanceCriteria.task:id,acceptance_criterion_id,status', 'tasks:id,story_id,status']);

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
