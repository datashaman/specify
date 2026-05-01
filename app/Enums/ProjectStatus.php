<?php

namespace App\Enums;

/** Lifecycle of a Project: Planned → Active → Completed → Archived. */
enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';
}
