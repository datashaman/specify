<?php

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\Story;
use InvalidArgumentException;

class CurrentPlanSelector
{
    public function setCurrent(Story $story, Plan $plan): void
    {
        if ((int) $plan->story_id !== (int) $story->getKey()) {
            throw new InvalidArgumentException('Plan does not belong to this story.');
        }

        $story->forceFill(['current_plan_id' => $plan->getKey()])->save();
    }
}
