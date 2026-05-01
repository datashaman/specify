<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound webhook payload (push, PR, etc.) routed to a Repo.
 *
 * `signature_valid` records HMAC verification against the Repo's
 * `webhook_secret`; `matched_run_id` links the event to the AgentRun whose
 * push triggered it (when one can be matched).
 */
#[Fillable(['repo_id', 'provider', 'event', 'action', 'delivery_id', 'signature_valid', 'matched_run_id', 'payload'])]
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
