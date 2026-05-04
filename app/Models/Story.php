<?php

namespace App\Models;

use App\Enums\StoryKind;
use App\Enums\StoryStatus;
use App\Models\Concerns\HasSlug;
use App\Services\Approvals\ApprovalPolicyResolver;
use App\Services\ApprovalService;
use App\Services\Stories\StoryPullRequestProjection;
use App\Services\Stories\StoryRunProjection;
use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Product contract under a Feature.
 *
 * Holds structured story framing, acceptance criteria, scenarios, and one or
 * more implementation plans. `current_plan_id` identifies the active plan.
 */
#[Fillable(['feature_id', 'created_by_id', 'name', 'slug', 'kind', 'actor', 'intent', 'outcome', 'description', 'notes', 'status', 'revision', 'position', 'current_plan_id'])]
class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory, HasSlug;

    protected function slugScopeColumn(): string
    {
        return 'feature_id';
    }

    protected static bool $suppressRevisionBump = false;

    /**
     * Force a status/revision change without firing the model's updated-hook
     * recompute. Use when the caller has already decided the new state and
     * explicitly does NOT want auto-approve / auto-execute to kick in
     * (e.g. plan generation reopens approval and must wait for human review).
     */
    public function silentlyForceFill(array $attributes): void
    {
        self::$suppressRevisionBump = true;
        try {
            $this->forceFill($attributes)->save();
        } finally {
            self::$suppressRevisionBump = false;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $story) {
            if (empty($story->position)) {
                $max = static::where('feature_id', $story->feature_id)->max('position') ?? 0;
                $story->position = $max + 1;
            }
        });

        static::updating(function (self $story) {
            if (self::$suppressRevisionBump) {
                return;
            }
            $watched = ['name', 'kind', 'actor', 'intent', 'outcome', 'description', 'notes'];
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

            $currentPlan = $story->currentPlan()->first();
            if ($currentPlan) {
                $currentPlan->reopenForApproval();
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
            'kind' => StoryKind::class,
            'status' => StoryStatus::class,
            'revision' => 'integer',
            'position' => 'integer',
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderByDesc('version');
    }

    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'plan_id', 'current_plan_id')
            ->orderBy('position');
    }

    /**
     * True if any subtask under this story has an AgentRun that is still
     * active (queued or running). Single source of truth for "this story
     * is mid-execution" — UI gates and rail roll-ups read this.
     */
    public function hasActiveSubtaskRun(): bool
    {
        return app(StoryRunProjection::class)->hasActiveSubtaskRun($this);
    }

    /**
     * Latest in-flight `ResolveConflicts` run for any subtask under this story.
     */
    public function activeConflictResolutionAgentRun(): ?AgentRun
    {
        return app(StoryRunProjection::class)->activeConflictResolutionRun($this);
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

    /**
     * Pull requests associated with this Story — the PR is a Story-level
     * artefact (every Subtask AgentRun on a single-driver story commits to
     * the same `specify/<feature>/<story>` branch; the first run opens the
     * PR, the rest add commits). Race mode (ADR-0006) opens N PRs, one per
     * driver branch — each is a separate entry in the returned collection.
     *
     * Pulled from each Execute-kind Subtask AgentRun whose output carries
     * a `pull_request_url`. RespondToReview runs (ADR-0008) are excluded
     * because they push commits to an already-open PR; their
     * `pull_request_number` is just a back-pointer, not a new PR.
     *
     * Each entry: `{ url, number, driver, branch, run_id, merged, action,
     * run_finished_at, mergeable, mergeable_state }`. `mergeable` and
     * `mergeable_state` come from a best-effort GitHub API probe (null when
     * unknown, non-GitHub repos, or API errors). `run_finished_at` is the
     * terminal timestamp of the AgentRun that recorded the PR, *not* the PR's
     * upstream open time — we don't fetch that. Ordered by most recent run
     * first; any merged PR is hoisted to the top.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pullRequests(): Collection
    {
        return app(StoryPullRequestProjection::class)->all($this);
    }

    /**
     * The single canonical PR for this Story, if there is one. Returns
     * the merged PR if any sibling has been merged; otherwise the sole
     * entry in single-driver mode; otherwise null in pre-merge race mode
     * where the candidates are still equally valid.
     *
     * Use `pullRequests()` when you want to render every candidate.
     *
     * @return array<string, mixed>|null
     */
    public function primaryPullRequest(): ?array
    {
        return app(StoryPullRequestProjection::class)->primary($this);
    }

    public function effectivePolicy(): ApprovalPolicy
    {
        return app(ApprovalPolicyResolver::class)->forStory($this);
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

    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class)->orderBy('position');
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
