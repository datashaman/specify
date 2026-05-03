<?php

namespace App\Enums;

enum StoryKind: string
{
    case UserStory = 'user_story';
    case Requirement = 'requirement';
    case Enabler = 'enabler';
}
