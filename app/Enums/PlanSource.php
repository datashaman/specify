<?php

namespace App\Enums;

enum PlanSource: string
{
    case Human = 'human';
    case Ai = 'ai';
    case Imported = 'imported';
}
