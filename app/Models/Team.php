<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['workspace_id', 'name', 'slug', 'description'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function addMember(User $user, TeamRole $role = TeamRole::Member): void
    {
        $this->members()->syncWithoutDetaching([
            $user->getKey() => ['role' => $role->value],
        ]);
    }

    public function roleFor(User $user): ?TeamRole
    {
        $role = $this->members()->whereKey($user->getKey())->value('team_user.role');

        return $role ? TeamRole::from($role) : null;
    }

    public function removeMember(User $user): void
    {
        $this->members()->detach($user->getKey());

        User::query()
            ->whereKey($user->getKey())
            ->where('current_team_id', $this->getKey())
            ->update(['current_team_id' => null]);
    }
}
