<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['repo_id', 'provider', 'event', 'action', 'signature_valid', 'matched_run_id', 'payload'])]
class WebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function repo(): BelongsTo
    {
        return $this->belongsTo(Repo::class);
    }

    public function matchedRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'matched_run_id');
    }
}
