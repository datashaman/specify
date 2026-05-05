<?php

namespace App\Services\Ai;

/**
 * Runtime provider name and optional model resolved from a user's BYOK key.
 */
class ByokProvider
{
    public function __construct(
        public string $provider,
        public ?string $model = null,
    ) {}
}
