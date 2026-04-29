<?php

namespace App\Enums;

enum FeatureStatus: string
{
    case Proposed = 'proposed';
    case Active = 'active';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
