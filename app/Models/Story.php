<?php

namespace App\Models;

use App\Enums\AgentRunKind;
use App\Enums\StoryStatus;
use App\Models\Concerns\HasSlug;
use App\Services\ApprovalService;
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
 * Product owner's unit of value within a Feature.
 *
 * Carries acceptance criteria, an immutable `revision` counter (auto-bumped
 * on plan replacement), and the only approval gate in the system
 * (see ADR-0001). Tasks attach directly to the Story (ADR-0002).
 */
#[Fillable(['feature_id', 'created_by_id', 'name', 'slug', 'description', 'notes', 'status', 'revision'])]
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
     * opened_at }`. Ordered by most recent first; the merged one (if any)
     * is hoisted to the top.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pullRequests(): Collection
    {
        $subtaskIds = $this->tasks()
            ->with('subtasks:id,task_id')
            ->get()
            ->flatMap(fn ($t) => $t->subtasks->pluck('id'))
            ->all();

        if ($subtaskIds === []) {
            return collect();
        }

        $entries = AgentRun::query()
            ->where('runnable_type', Subtask::class)
            ->whereIn('runnable_id', $subtaskIds)
            ->where('kind', AgentRunKind::Execute->value)
            ->whereJsonContainsKey('output->pull_request_url')
            ->orderByDesc('id')
            ->get(['id', 'executor_driver', 'working_branch', 'output', 'finished_at'])
            ->map(function (AgentRun $run) {
                $o = (array) $run->output;
                $url = (string) ($o['pull_request_url'] ?? '');
                if ($url === '') {
                    return null;
                }

                return [
                    'url' => $url,
                    'number' => $o['pull_request_number'] ?? null,
                    'driver' => $run->executor_driver,
                    'branch' => $run->working_branch,
                    'run_id' => $run->getKey(),
                    'merged' => $o['pull_request_merged'] ?? null,
                    'action' => $o['pull_request_action'] ?? null,
                    'opened_at' => $run->finished_at,
                ];
            })
            ->filter()
            ->values();

        // Deduplicate on URL: when a PR retry adopts an already-open PR
        // every retried run records the same URL. Keep the freshest one
        // (the most recent run wins because we ordered by id desc).
        $entries = $entries
            ->groupBy('url')
            ->map(fn (Collection $g) => $g->first())
            ->values();

        // Hoist merged PRs to the top.
        return $entries
            ->sortBy(fn ($pr) => $pr['merged'] === true ? 0 : 1)
            ->values();
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
        $prs = $this->pullRequests();
        if ($prs->isEmpty()) {
            return null;
        }

        $merged = $prs->first(fn ($pr) => $pr['merged'] === true);
        if ($merged !== null) {
            return $merged;
        }

        return $prs->count() === 1 ? $prs->first() : null;
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
