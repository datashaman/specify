<?php

namespace App\Http\Controllers;

use App\Models\ContextItem;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoryContextItemsController extends Controller
{
    public function store(Request $request, Project $project, Story $story): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless(in_array((int) $project->getKey(), $user->accessibleProjectIds(), true), 404);

        $story->loadMissing('feature.project');
        abort_unless((int) $story->feature->project_id === (int) $project->getKey(), 404);
        abort_unless($this->canManageContextItems($user, $story), 403);

        $validated = $request->validate([
            'context_item_ids' => ['required', 'array', 'min:1'],
            'context_item_ids.*' => [
                'required',
                'integer',
                Rule::exists('context_items', 'id')
                    ->where('project_id', $project->getKey()),
            ],
        ]);

        $ids = collect($validated['context_item_ids'])
            ->map(fn (int|string $id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $story->contextItems()->syncWithoutDetaching($ids);

        return response()->json([
            'story_id' => $story->getKey(),
            'context_items' => $story->contextItems()
                ->orderBy('context_items.id')
                ->get()
                ->map(fn (ContextItem $item) => [
                    'id' => $item->getKey(),
                    'project_id' => $item->project_id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'description' => $item->description,
                    'metadata' => $item->metadata,
                ])
                ->all(),
        ]);
    }

    public function destroy(Request $request, Project $project, Story $story, ContextItem $contextItem): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless(in_array((int) $project->getKey(), $user->accessibleProjectIds(), true), 404);

        $story->loadMissing('feature.project');
        abort_unless((int) $story->feature->project_id === (int) $project->getKey(), 404);
        abort_unless((int) $contextItem->project_id === (int) $project->getKey(), 404);
        abort_unless($this->canManageContextItems($user, $story), 403);

        $story->contextItems()->detach($contextItem->getKey());

        return response()->json([
            'story_id' => $story->getKey(),
            'context_item_id' => $contextItem->getKey(),
            'detached' => true,
        ]);
    }

    private function canManageContextItems(User $user, Story $story): bool
    {
        return (int) $story->created_by_id === (int) $user->getKey()
            || $user->canApproveInProject($story->feature->project);
    }
}
