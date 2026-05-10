<?php

namespace App\Services\Context;

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
use App\Models\ContextItem;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use App\Services\Stories\StoryRevisionLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Mutating surface for ContextItem rows. Story-scoped CRUD reopens the
 * owning Story for approval (single revision per write); project-scoped
 * CRUD does not — see ADR for the rationale.
 */
class ContextItemWriter
{
    public function __construct(private StoryRevisionLifecycle $revisions) {}

    /**
     * @param  array{
     *     type: ContextItemType|string,
     *     title: string,
     *     description?: string|null,
     *     metadata?: array<string, mixed>|null,
     * }  $attributes
     */
    public function createProjectItem(Project $project, array $attributes, User $actor): ContextItem
    {
        $this->ensureNonFileType($attributes['type'] ?? null);

        return ContextItem::create([
            'project_id' => $project->getKey(),
            'story_id' => null,
            'type' => $attributes['type'],
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'summary_status' => $this->initialSummaryStatus($attributes['type']),
            'created_by_id' => $actor->getKey(),
        ]);
    }

    /**
     * @param  array{
     *     type: ContextItemType|string,
     *     title: string,
     *     description?: string|null,
     *     metadata?: array<string, mixed>|null,
     * }  $attributes
     */
    public function createStoryItem(Story $story, array $attributes, User $actor): ContextItem
    {
        $this->ensureNonFileType($attributes['type'] ?? null);

        return DB::transaction(function () use ($story, $attributes, $actor): ContextItem {
            $projectId = $this->projectIdFor($story);

            $item = ContextItem::create([
                'project_id' => $projectId,
                'story_id' => $story->getKey(),
                'type' => $attributes['type'],
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'summary_status' => $this->initialSummaryStatus($attributes['type']),
                'created_by_id' => $actor->getKey(),
            ]);

            $story->includedContextItems()->syncWithoutDetaching([
                $item->getKey() => [
                    'included_at' => now(),
                    'included_by_id' => $actor->getKey(),
                ],
            ]);

            $this->revisions->recordContentArtifactChanged($story);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(ContextItem $item, array $changes, User $actor): ContextItem
    {
        return DB::transaction(function () use ($item, $changes): ContextItem {
            $allowed = ['title', 'description', 'metadata'];
            $payload = array_intersect_key($changes, array_flip($allowed));

            if ($payload === []) {
                return $item;
            }

            $item->forceFill($payload);

            if (! $item->isDirty()) {
                return $item;
            }

            $item->save();

            if ($item->isStoryScoped() && $item->story) {
                $this->revisions->recordContentArtifactChanged($item->story);
            }

            return $item->refresh();
        });
    }

    public function delete(ContextItem $item, User $actor): void
    {
        DB::transaction(function () use ($item): void {
            $story = $item->isStoryScoped() ? $item->story : null;

            $this->deleteUnderlyingFile($item);

            $item->delete();

            if ($story) {
                $this->revisions->recordContentArtifactChanged($story);
            }
        });
    }

    private function projectIdFor(Story $story): int
    {
        $projectId = $story->feature?->project_id;
        if ($projectId === null) {
            throw new InvalidArgumentException('Story is not attached to a Feature with a Project.');
        }

        return (int) $projectId;
    }

    private function ensureNonFileType(mixed $type): void
    {
        $resolved = $type instanceof ContextItemType
            ? $type
            : ContextItemType::tryFrom((string) $type);

        if ($resolved === ContextItemType::File) {
            throw new InvalidArgumentException('Use AssetUploader to create file-typed ContextItems.');
        }

        if ($resolved === null) {
            throw new InvalidArgumentException('Unknown ContextItem type.');
        }
    }

    private function initialSummaryStatus(mixed $type): ContextItemSummaryStatus
    {
        $resolved = $type instanceof ContextItemType
            ? $type
            : ContextItemType::tryFrom((string) $type);

        return $resolved === ContextItemType::Link
            ? ContextItemSummaryStatus::Skipped
            : ContextItemSummaryStatus::Pending;
    }

    private function deleteUnderlyingFile(ContextItem $item): void
    {
        if ($item->type !== ContextItemType::File) {
            return;
        }

        $disk = (string) ($item->metadata['disk'] ?? '');
        $path = (string) ($item->metadata['path'] ?? '');
        if ($disk === '' || $path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }
}
