<?php

namespace App\Models;

use App\Enums\ContextItemSummaryStatus;
use App\Enums\ContextItemType;
use Database\Factories\ContextItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Project- or Story-scoped reference asset that feeds the AI context brief.
 *
 * `project_id` is always set; `story_id` is set only for story-scoped items
 * that auto-include into that Story. Selection of project-scoped items into
 * a Story flows through the `context_item_story` pivot.
 */
#[Fillable([
    'project_id',
    'story_id',
    'type',
    'title',
    'description',
    'metadata',
    'summary',
    'summary_status',
    'summary_error',
    'created_by_id',
])]
class ContextItem extends Model
{
    /** @use HasFactory<ContextItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Soft cap for raw text returned by `bodyForContext()` when no summary
     * is available. Roughly 4 KB of UTF-8 — enough to keep plans usable
     * without dominating the prompt budget.
     */
    public const BODY_FALLBACK_CHARS = 4000;

    protected function casts(): array
    {
        return [
            'type' => ContextItemType::class,
            'summary_status' => ContextItemSummaryStatus::class,
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function includedInStories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'context_item_story')
            ->withPivot(['included_at', 'included_by_id']);
    }

    public function isProjectScoped(): bool
    {
        return $this->story_id === null;
    }

    public function isStoryScoped(): bool
    {
        return $this->story_id !== null;
    }

    /**
     * The text rendered into the AI prompt. Prefers a ready summary; falls
     * back to the truncated raw text body so plans still generate when
     * summarisation was skipped or the item never went through compression.
     */
    public function bodyForContext(): string
    {
        if ($this->summary_status === ContextItemSummaryStatus::Ready && filled($this->summary)) {
            return (string) $this->summary;
        }

        $raw = $this->rawBody();
        if ($raw === '') {
            return '';
        }

        return Str::limit($raw, self::BODY_FALLBACK_CHARS, '…');
    }

    private function rawBody(): string
    {
        $type = $this->type instanceof ContextItemType ? $this->type : ContextItemType::tryFrom((string) $this->type);

        return match ($type) {
            ContextItemType::Text => (string) ($this->metadata['body'] ?? $this->description ?? ''),
            ContextItemType::Link => (string) ($this->metadata['url'] ?? ''),
            ContextItemType::File => (string) ($this->metadata['original_name'] ?? $this->metadata['path'] ?? ''),
            null => (string) ($this->description ?? ''),
        };
    }
}
