<?php

namespace App\Ai\Agents;

use App\Services\Prompts\PromptLoader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Reads accepted ADRs and a PR diff; emits a structured list of contradictions.
 *
 * One narrow persona — does not review for code quality, style, performance,
 * or security. The instruction prompt lives in `prompts/adr-conformance-reviewer.md`
 * so it participates in code review like every other agent prompt.
 */
#[Provider(Lab::Anthropic)]
#[UseSmartestModel]
#[MaxTokens(2048)]
class AdrConformanceReviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<string, string>  $adrs  Filename => markdown body.
     * @param  list<string>  $files  Files touched by the diff.
     */
    public function __construct(
        public array $adrs,
        public string $diff,
        public array $files,
    ) {}

    public function instructions(): string
    {
        return app(PromptLoader::class)->load('adr-conformance-reviewer');
    }

    public function buildPrompt(): string
    {
        $adrSection = '';
        foreach ($this->adrs as $name => $body) {
            $adrSection .= "# ADR file: `{$name}`\n\n".trim($body)."\n\n";
        }

        $fileSection = $this->files === [] ? '(none)' : implode("\n", array_map(fn ($f) => '- '.$f, $this->files));

        return <<<PROMPT
Accepted ADRs:

{$adrSection}

Files touched:

{$fileSection}

Unified diff:

```diff
{$this->diff}
```

Review the diff against the ADRs and return your structured response.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'overall' => $schema->string()->enum(['pass', 'warn', 'fail'])->required(),
            'summary' => $schema->string()->required(),
            'violations' => $schema->array()
                ->items(
                    $schema->object(fn ($schema) => [
                        'adr' => $schema->string()->required(),
                        'file' => $schema->string()->required(),
                        'line' => $schema->integer(),
                        'reason' => $schema->string()->required(),
                        'severity' => $schema->string()
                            ->enum(['info', 'warning', 'error'])
                            ->required(),
                    ])
                ),
        ];
    }
}
