<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Http\Requests\StoreProjectContextItemRequest;
use App\Http\Requests\UpdateProjectContextItemRequest;
use App\Http\Resources\ContextItemResource;
use App\Models\ContextItem;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectContextItemController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        abort_unless(
            in_array($request->user()->roleInTeam($project->team_id), TeamRole::cases(), true),
            403,
        );

        return ContextItemResource::collection(
            $project->contextItems()
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreProjectContextItemRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();

        $contextItem = $project->contextItems()->create([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'metadata' => $this->metadataFor($request, $project, $validated),
        ]);

        return (new ContextItemResource($contextItem))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProjectContextItemRequest $request, Project $project, ContextItem $contextItem): JsonResponse
    {
        $this->abortUnlessContextItemBelongsToProject($contextItem, $project);

        $contextItem->fill($request->safe()->only(['title', 'description']));
        $contextItem->save();

        return (new ContextItemResource($contextItem->refresh()))
            ->response();
    }

    public function destroy(Request $request, Project $project, ContextItem $contextItem): Response
    {
        abort_unless($request->user()->canManageProject($project), 403);

        $this->abortUnlessContextItemBelongsToProject($contextItem, $project);

        $contextItem->delete();

        return response()->noContent();
    }

    /**
     * @param  array{type: string, url?: string, body?: string}  $validated
     * @return array<string, mixed>
     */
    private function metadataFor(StoreProjectContextItemRequest $request, Project $project, array $validated): array
    {
        if ($validated['type'] === 'link') {
            return ['url' => $validated['url']];
        }

        if ($validated['type'] === 'text') {
            return ['body' => $validated['body']];
        }

        $file = $request->file('file');
        $path = $file->store("context-items/{$project->getKey()}", 'local');

        abort_if($path === false, 500, 'The context file could not be stored.');

        return [
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function abortUnlessContextItemBelongsToProject(ContextItem $contextItem, Project $project): void
    {
        abort_unless($contextItem->project_id === $project->getKey(), 404);
    }
}
