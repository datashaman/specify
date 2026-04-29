<?php

namespace App\Actions\Workspaces;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapPersonalWorkspace
{
    public function handle(User $user): Team
    {
        return DB::transaction(function () use ($user) {
            $workspace = Workspace::create([
                'owner_id' => $user->getKey(),
                'name' => $user->name."'s Workspace",
                'slug' => Str::slug($user->name).'-'.Str::lower(Str::random(6)),
            ]);

            $team = Team::create([
                'workspace_id' => $workspace->getKey(),
                'name' => 'Default',
                'slug' => 'default',
            ]);

            $team->addMember($user, TeamRole::Owner);
            $user->switchTeam($team);

            return $team;
        });
    }
}
