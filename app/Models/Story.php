<?php

namespace App\Models;

use App\Enums\StoryStatus;
use App\Services\ApprovalService;
use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

#[Fillable(['feature_id', 'created_by_id', 'name', 'description', 'notes', 'status', 'revision'])]
class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory;

    protected static bool $suppressRevisionBump = false;

    protected static function booted(): void
    {
        static::updating(function (self $story) {
            if (self::$suppressRevisionBump) {
                return;
            }
            $watched = ['name', 'description'];
            if (! collect($watched)->contains(fn ($key) => $story->isDirty($key))) {
                return;
            }
            if ($story->isDirty('revision')) {
                return;
            }
            $story->revision = ($story->revision ?? 1) + 1;
        });

        static::updated(function (self $story) {
            if (self::$suppressRevisionBump) {
                return;
            }
            if (! $story->wasChanged('revision')) {
                return;
            }
            if (in_array($story->status, [StoryStatus::Draft, StoryStatus::Rejected, StoryStatus::Done, StoryStatus::Cancelled], true)) {
                return;
            }
            self::$suppressRevisionBump = true;
            try {
                app(ApprovalService::class)->recompute($story);
            } finally {
                self::$suppressRevisionBump = false;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => StoryStatus::class,
            'revision' => 'integer',
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(StoryApproval::class);
    }

    public function agentRuns(): MorphMany
    {
        return $this->morphMany(AgentRun::class, 'runnable');
    }

    public function effectivePolicy(): ApprovalPolicy
    {
        $project = $this->feature?->project;
        $workspace = $project?->team?->workspace;

        $candidates = collect([
            ApprovalPolicy::query()
                ->where('scope_type', ApprovalPolicy::SCOPE_STORY)
                ->where('scope_id', $this->getKey())
                ->first(),
            $project ? ApprovalPolicy::query()
                ->where('scope_type', ApprovalPolicy::SCOPE_PROJECT)
                ->where('scope_id', $project->getKey())
                ->first() : null,
            $workspace ? ApprovalPolicy::query()
                ->where('scope_type', ApprovalPolicy::SCOPE_WORKSPACE)
                ->where('scope_id', $workspace->getKey())
                ->first() : null,
        ])->filter();

        return $candidates->first() ?? ApprovalPolicy::default();
    }

    public function isApproved(): bool
    {
        return $this->status === StoryStatus::Approved;
    }

    public function submitForApproval(): void
    {
        if ($this->status === StoryStatus::Rejected) {
            throw new \RuntimeException('Cannot submit a rejected story.');
        }
        if ($this->acceptanceCriteria()->count() === 0) {
            throw new \RuntimeException('Add at least one acceptance criterion before submitting.');
        }
        $this->status = StoryStatus::PendingApproval;
        $this->save();
        app(ApprovalService::class)->recompute($this);
    }

    public function acceptanceCriteria(): HasMany
    {
        return $this->hasMany(AcceptanceCriterion::class)->orderBy('position');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'story_dependencies', 'story_id', 'depends_on_story_id');
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'story_dependencies', 'depends_on_story_id', 'story_id');
    }

    public function addDependency(self $other): void
    {
        if ($this->is($other)) {
            throw new InvalidArgumentException('A story cannot depend on itself.');
        }

        if ($this->workspaceId() !== $other->workspaceId()) {
            throw new InvalidArgumentException('Story dependencies must live in the same workspace.');
        }

        if ($other->dependsOnTransitively($this)) {
            throw new InvalidArgumentException('Adding this dependency would create a cycle.');
        }

        $this->dependencies()->syncWithoutDetaching([$other->getKey()]);
    }

    public function dependsOnTransitively(self $candidate): bool
    {
        $visited = [];
        $stack = [$this->getKey()];

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $deps = self::find($id)?->dependencies()->pluck('stories.id')->all() ?? [];
            foreach ($deps as $depId) {
                if ($depId === $candidate->getKey()) {
                    return true;
                }
                $stack[] = $depId;
            }
        }

        return false;
    }

    public function isReady(): bool
    {
        return $this->dependencies()
            ->where('status', '!=', StoryStatus::Done->value)
            ->doesntExist();
    }

    public function workspaceId(): ?int
    {
        return $this->feature?->project?->team?->workspace_id;
    }
}
