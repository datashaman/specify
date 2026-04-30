<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\SubtaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['task_id', 'position', 'name', 'description', 'status'])]
class Subtask extends Model
{
    /** @use HasFactory<SubtaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agentRuns(): MorphMany
    {
        return $this->morphMany(AgentRun::class, 'runnable');
    }

    public function latestRun(): ?AgentRun
    {
        return $this->agentRuns()->latest('id')->first();
    }
}
