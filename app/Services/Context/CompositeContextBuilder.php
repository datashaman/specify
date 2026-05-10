<?php

namespace App\Services\Context;

use App\Models\Repo;
use App\Models\Subtask;

/**
 * Chains multiple ContextBuilders, concatenating their non-empty briefs in
 * order with a blank line between sections. Empty briefs are dropped, so a
 * builder that has nothing to say doesn't leave dead whitespace in the
 * prompt.
 */
class CompositeContextBuilder implements ContextBuilder
{
    /** @var list<ContextBuilder> */
    private array $builders;

    public function __construct(ContextBuilder ...$builders)
    {
        $this->builders = array_values($builders);
    }

    public function build(Subtask $subtask, ?string $workingDir, ?Repo $repo): string
    {
        $briefs = [];
        foreach ($this->builders as $builder) {
            $brief = trim($builder->build($subtask, $workingDir, $repo));
            if ($brief !== '') {
                $briefs[] = $brief;
            }
        }

        return implode("\n\n", $briefs);
    }
}
