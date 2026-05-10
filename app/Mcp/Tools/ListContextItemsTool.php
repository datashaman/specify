<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\ContextItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List context assets for a project or story. Pass project_id to see project-level assets; pass story_id to see story-scoped assets only.')]
class ListContextItemsTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-context-items';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'story_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['story_id'])) {
            $story = $this->resolveAccessibleStory($validated['story_id'], $user);
            if ($story instanceof Response) {
                return $story;
            }

            $items = ContextItem::query()
                ->where('story_id', $story->id)
                ->orderByDesc('id')
                ->get();
        } elseif (! empty($validated['project_id'])) {
            $project = $this->resolveAccessibleProject($validated['project_id'], $user);
            if ($project instanceof Response) {
                return $project;
            }

            $items = ContextItem::query()
                ->where('project_id', $project->id)
                ->whereNull('story_id')
                ->orderByDesc('id')
                ->get();
        } else {
            return Response::error('Provide either project_id or story_id.');
        }

        return Response::json($items->map(fn ($item) => [
            'id' => $item->id,
            'project_id' => $item->project_id,
            'story_id' => $item->story_id,
            'type' => $item->type->value,
            'title' => $item->title,
            'metadata' => $item->metadata,
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('List project-level assets (no story scope). Mutually exclusive with story_id.'),
            'story_id' => $schema->integer()->description('List story-scoped assets only. Mutually exclusive with project_id.'),
        ];
    }
}
