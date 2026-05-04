<?php

use Database\Factories\TaskFactory;

arch('task factory helper names current plan ownership')
    ->expect(TaskFactory::class)
    ->toHaveMethod('forCurrentPlanOf')
    ->not->toHaveMethod('for'.'Story');
