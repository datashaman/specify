<?php

namespace App\Enums;

/** Lifecycle of a Feature: Proposed → Active → Done (or Cancelled). */
enum FeatureStatus: string
{
    case Proposed = 'proposed';
    case Active = 'active';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
