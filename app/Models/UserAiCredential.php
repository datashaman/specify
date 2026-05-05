<?php

namespace App\Models;

use Database\Factories\UserAiCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encrypted per-user AI provider credential for BYOK execution.
 */
#[Fillable(['user_id', 'provider', 'api_key', 'model', 'enabled'])]
#[Hidden(['api_key'])]
class UserAiCredential extends Model
{
    /** @use HasFactory<UserAiCredentialFactory> */
    use HasFactory;

    public const PROVIDER_ANTHROPIC = 'anthropic';

    public const PROVIDER_OPENAI = 'openai';

    /** @return list<string> */
    public static function supportedProviders(): array
    {
        return [self::PROVIDER_ANTHROPIC, self::PROVIDER_OPENAI];
    }

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
