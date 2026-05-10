<?php

namespace App\Ai\Agents;

use App\Models\ContextItem;
use App\Services\Prompts\PromptLoader;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Compresses a single ContextItem body into a ≤1 KB summary the
 * plan-generation agent can quote without dominating its prompt budget.
 *
 * Output is plain text (no structured schema). Trigger via
 * `App\Services\Ai\ContextCompressor`, which handles BYOK resolution,
 * skipped/failed status writes, and "no creds" fallbacks.
 */
#[Provider(Lab::Anthropic)]
#[UseCheapestModel]
#[MaxTokens(1024)]
class ContextSummariser implements Agent
{
    use Promptable;

    public function __construct(public ContextItem $item, public string $body) {}

    public function instructions(): string
    {
        return app(PromptLoader::class)->load('context-summariser');
    }

    public function buildPrompt(): string
    {
        $title = $this->item->title;
        $type = $this->item->type?->value ?? 'unknown';

        return <<<PROMPT
Title: {$title}
Type: {$type}

Body:
{$this->body}
PROMPT;
    }
}
