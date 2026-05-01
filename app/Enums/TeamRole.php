<?php

namespace App\Enums;

/**
 * Role a User holds on a Team (workspace-scoped) via the team_user pivot.
 *
 * Owner is unique per team (the workspace owner); Admin can manage members;
 * Member has standard access.
 */
enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
