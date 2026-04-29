<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user along with a personal workspace and default team.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

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

            return $user;
        });
    }
}
