<?php

namespace App\Enums;

enum ContextItemType: string
{
    case File = 'file';
    case Link = 'link';
    case Text = 'text';
}
