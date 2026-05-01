<?php

namespace App\Services\Context;

use App\Models\Repo;
use App\Models\Subtask;

/**
 * Layered, repo-aware context injected into the executor's prompt before each
 * Subtask runs. Composes on top of the static markdown prompts in `prompts/`
 * (loaded by PromptLoader) — those describe *how the agent works*; this
 * describes *what the agent is about to touch*.
 *
 * Implementations must be cheap (≤ 1s wall-clock budget) and bounded in
 * output size; callers prepend the result verbatim into a finite prompt.
 */
interface ContextBuilder
{
    /**
     * Produce a per-subtask context brief, or empty string when nothing
     * useful is available (e.g. no working directory, or builder disabled).
     */
    public function build(Subtask $subtask, ?string $workingDir, ?Repo $repo): string;
}
