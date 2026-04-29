<?php

namespace App\Enums;

enum RepoProvider: string
{
    case Github = 'github';
    case Gitlab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Generic = 'generic';
}
