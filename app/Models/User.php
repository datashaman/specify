<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'current_team_id', 'current_project_id', 'github_id', 'avatar_url', 'github_token', 'github_refresh_token', 'github_token_expires_at', 'github_scopes'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'github_token', 'github_refresh_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'github_token' => 'encrypted',
            'github_refresh_token' => 'encrypted',
            'github_token_expires_at' => 'datetime',
            'github_scopes' => 'array',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function currentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'current_project_id');
    }

    public function currentWorkspace(): ?Workspace
    {
        return $this->currentTeam?->workspace;
    }

    /**
     * @return Collection<int, Workspace>
     */
    public function accessibleWorkspaces(): Collection
    {
        $teamIds = $this->teams()->pluck('teams.id');

        return Workspace::query()
            ->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $teamIds))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Team>
     */
    public function accessibleTeamsIn(Workspace $workspace): \Illuminate\Database\Eloquent\Collection
    {
        return $this->teams()
            ->where('teams.workspace_id', $workspace->getKey())
            ->orderBy('teams.id')
            ->get();
    }

    /**
     * Projects in the current team's workspace, restricted to teams the user is a member of.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    public function accessibleProjectsInCurrentWorkspace(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->currentWorkspace();
        if ($workspace === null) {
            return Project::query()->whereRaw('0=1')->get();
        }

        $teamIds = $this->teams()->where('teams.workspace_id', $workspace->getKey())->pluck('teams.id');

        return Project::query()
            ->whereIn('team_id', $teamIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int>
     */
    public function accessibleProjectIds(): array
    {
        return Project::query()
            ->whereIn('team_id', $this->teams()->pluck('teams.id'))
            ->pluck('id')
            ->all();
    }

    /**
     * Sticky scope: a single project when current_project_id is set,
     * otherwise every project in the current workspace the user can access.
     *
     * @return array<int>
     */
    public function scopedProjectIds(): array
    {
        if ($this->current_project_id) {
            $accessible = $this->accessibleProjectIds();

            return in_array((int) $this->current_project_id, $accessible, true)
                ? [(int) $this->current_project_id]
                : [];
        }

        return $this->accessibleProjectsInCurrentWorkspace()->pluck('id')->all();
    }

    public function roleInTeam(int $teamId): ?TeamRole
    {
        $row = $this->teams()->where('teams.id', $teamId)->first();
        $value = $row?->pivot->role ?? null;

        return $value ? TeamRole::from($value) : null;
    }

    public function canApproveInProject(Project $project): bool
    {
        return in_array(
            $this->roleInTeam($project->team_id),
            [TeamRole::Owner, TeamRole::Admin],
            true,
        );
    }

    public function switchTeam(Team $team): void
    {
        if (! $this->teams()->whereKey($team->getKey())->exists()) {
            throw new InvalidArgumentException('User is not a member of this team.');
        }

        $this->forceFill(['current_team_id' => $team->getKey()])->save();
        $this->setRelation('currentTeam', $team);
    }

    public function switchWorkspace(Workspace $workspace): void
    {
        $team = $this->accessibleTeamsIn($workspace)->first();
        if ($team === null) {
            throw new InvalidArgumentException('User has no team in this workspace.');
        }

        $this->forceFill([
            'current_team_id' => $team->getKey(),
            'current_project_id' => null,
        ])->save();
        $this->setRelation('currentTeam', $team);
    }

    public function switchProject(?Project $project): void
    {
        if ($project !== null && ! in_array((int) $project->getKey(), $this->accessibleProjectIds(), true)) {
            throw new InvalidArgumentException('User cannot scope to this project.');
        }

        $this->forceFill(['current_project_id' => $project?->getKey()])->save();
        $this->setRelation('currentProject', $project);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
