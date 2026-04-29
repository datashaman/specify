<?php

namespace App\Models;

use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Services\ApprovalService;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['story_id', 'version', 'summary', 'status'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PlanApproval::class);
    }

    public function effectivePolicy(): ApprovalPolicy
    {
        $project = $this->story?->feature?->project;
        $workspace = $project?->team?->workspace;

        $candidates = collect([
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
        return $this->status === PlanStatus::Approved;
    }

    public function submitForApproval(): void
    {
        if ($this->status === PlanStatus::Rejected) {
            throw new \RuntimeException('Cannot submit a rejected plan.');
        }
        $this->status = PlanStatus::PendingApproval;
        $this->save();
        app(ApprovalService::class)->recompute($this);
    }

    /**
     * Pending tasks whose dependencies are all Done — what an executor can pick up next.
     *
     * @return Collection<int, Task>
     */
    public function nextActionableTasks(): Collection
    {
        return $this->tasks()
            ->where('status', TaskStatus::Pending->value)
            ->with('dependencies:id,status')
            ->get()
            ->filter(fn (Task $t) => $t->dependencies->every(fn (Task $d) => $d->status === TaskStatus::Done))
            ->values();
    }
}
