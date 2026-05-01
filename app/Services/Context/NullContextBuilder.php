<?php

namespace App\Services\Context;

use App\Models\Repo;
use App\Models\Subtask;

/**
 * Returns an empty brief. Bound in tests and as a safe default when
 * `specify.context.builder = null`.
 */
class NullContextBuilder implements ContextBuilder
{
    public function build(Subtask $subtask, ?string $workingDir, ?Repo $repo): string
    {
        return '';
    }
}
