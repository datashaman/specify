<?php

namespace App\Services\Context;

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
use App\Jobs\SummariseContextItemJob;
use App\Models\ContextItem;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persists an uploaded file to the configured `private` disk and creates a
 * matching ContextItem row. Story summarisation (lazy) is owned by the
 * companion job in Slice 2 — uploads here always land in
 * `summary_status=pending` so that worker picks them up later.
 */
class AssetUploader
{
    public function store(UploadedFile $file, Project $project, ?Story $story, User $actor): ContextItem
    {
        $this->ensureStoryBelongsToProject($project, $story);
        $this->validate($file);

        $disk = $this->disk();
        $directory = 'context/'.Str::lower((string) Str::ulid());
        $storedPath = $file->storeAs($directory, $this->safeFilename($file), ['disk' => $disk]);

        if ($storedPath === false) {
            throw new \RuntimeException('Failed to store uploaded asset.');
        }

        $item = ContextItem::create([
            'project_id' => $project->getKey(),
            'story_id' => $story?->getKey(),
            'type' => ContextItemType::File,
            'title' => $file->getClientOriginalName() ?: basename($storedPath),
            'description' => null,
            'metadata' => [
                'disk' => $disk,
                'path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                // Server-detected MIME — getClientMimeType() is user-controlled and
                // trivial to spoof. The detected value is what we record and what
                // later checks should rely on.
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ],
            'summary_status' => ContextItemSummaryStatus::Pending,
            'created_by_id' => $actor->getKey(),
        ]);

        if ($this->shouldSummariseFile($file)) {
            SummariseContextItemJob::dispatch($item->getKey());
        }

        return $item;
    }

    private function shouldSummariseFile(UploadedFile $file): bool
    {
        $mime = (string) $file->getClientMimeType();
        if (! str_starts_with($mime, 'text/') && $mime !== 'application/pdf') {
            return false;
        }

        $threshold = (int) config('specify.context.assets.summary_threshold_chars', 2000);

        return $file->getSize() >= $threshold;
    }

    private function ensureStoryBelongsToProject(Project $project, ?Story $story): void
    {
        if ($story === null) {
            return;
        }

        $storyProjectId = $story->feature?->project_id;
        if ((int) $storyProjectId !== (int) $project->getKey()) {
            throw new InvalidArgumentException('Story does not belong to the given project.');
        }
    }

    private function validate(UploadedFile $file): void
    {
        $maxKb = (int) config('specify.context.assets.max_file_kb', 10240);
        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($maxKb > 0 && $sizeKb > $maxKb) {
            throw new InvalidArgumentException("Uploaded file exceeds the {$maxKb} KB limit.");
        }

        $allowed = (array) config('specify.context.assets.allowed_mimes', []);
        $detected = $file->getMimeType() ?: 'application/octet-stream';
        if ($allowed !== [] && ! in_array($detected, $allowed, true)) {
            throw new InvalidArgumentException("MIME type {$detected} is not allowed.");
        }
    }

    private function safeFilename(UploadedFile $file): string
    {
        $original = $file->getClientOriginalName() ?: 'upload';
        $base = Str::of(pathinfo($original, PATHINFO_FILENAME))->slug()->limit(80, '');
        $ext = strtolower((string) $file->getClientOriginalExtension());

        $name = $base->isEmpty() ? 'upload' : (string) $base;

        return $ext === '' ? $name : "{$name}.{$ext}";
    }

    private function disk(): string
    {
        $disk = (string) config('specify.context.assets.disk', 'private');

        if (! array_key_exists($disk, config('filesystems.disks', []))) {
            throw new \RuntimeException("Configured assets disk '{$disk}' is not defined.");
        }

        // Touch the disk so misconfiguration surfaces here (not in storeAs).
        Storage::disk($disk);

        return $disk;
    }
}
