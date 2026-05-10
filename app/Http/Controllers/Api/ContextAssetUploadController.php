<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Story;
use App\Services\Context\AssetUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ContextAssetUploadController extends Controller
{
    public function __invoke(Request $request, AssetUploader $uploader): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:255'],
            'project_id' => ['required_without:story_id', 'nullable', 'integer', 'prohibited_with:story_id'],
            'story_id' => ['required_without:project_id', 'nullable', 'integer', 'prohibited_with:project_id'],
        ]);

        $user = $request->user();

        if (! empty($validated['story_id'])) {
            $story = Story::query()->with('feature')->find($validated['story_id']);

            if (! $story) {
                return response()->json(['error' => 'Story not found.'], 404);
            }

            $project = $story->feature?->project;

            if (! $project || ! in_array($project->id, $user->accessibleProjectIds(), true)) {
                return response()->json(['error' => 'Story not accessible.'], 403);
            }
        } else {
            $project = Project::query()->find($validated['project_id']);

            if (! $project) {
                return response()->json(['error' => 'Project not found.'], 404);
            }

            if (! in_array($project->id, $user->accessibleProjectIds(), true)) {
                return response()->json(['error' => 'Project not accessible.'], 403);
            }

            $story = null;
        }

        try {
            $item = $uploader->store($request->file('file'), $project, $story ?? null, $user);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if (isset($validated['title']) && $validated['title'] !== null) {
            $item->update(['title' => $validated['title']]);
        }

        return response()->json([
            'id' => $item->id,
            'project_id' => $item->project_id,
            'story_id' => $item->story_id,
            'type' => $item->type->value,
            'title' => $item->title,
            'metadata' => $item->metadata,
        ], 201);
    }
}
