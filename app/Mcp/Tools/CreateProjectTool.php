<?php

namespace App\Mcp\Tools;

use App\Enums\ProjectStatus;
use App\Enums\RepoProvider;
use App\Enums\TeamRole;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Mcp\GithubRepoCatalog;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: create-project
 */
#[Description('Create a project. Optionally attach GitHub repos in the same call. Switches the user’s current_project_id to the new project.')]
class CreateProjectTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'create-project';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'team_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'repos' => ['nullable', 'array'],
            'repos.*.full_name' => ['required_with:repos', 'string'],
            'repos.*.primary' => ['nullable', 'boolean'],
        ]);

        $teamId = $validated['team_id'] ?? $user->current_team_id;
        if (! $teamId) {
            return Response::error('No team_id provided and no current team set.');
        }

        $role = $user->roleInTeam($teamId);
        if (! in_array($role, [TeamRole::Owner, TeamRole::Admin], true)) {
            return Response::error('You must be Owner or Admin in this team to create a project.');
        }

        $team = Team::query()->find($teamId);
        if (! $team) {
            return Response::error('Team not found.');
        }

        $primariesRequested = collect($validated['repos'] ?? [])
            ->filter(fn ($r) => ! empty($r['primary']))
            ->count();
        if ($primariesRequested > 1) {
            return Response::error('At most one repo can be marked primary.');
        }

        $reposToAttach = [];
        if (! empty($validated['repos'])) {
            if (! $user->github_token) {
                return Response::error('Cannot resolve GitHub repos without a connected GitHub OAuth session.');
            }

            foreach ($validated['repos'] as $entry) {
                $match = GithubRepoCatalog::findByFullName($user, $entry['full_name']);
                if (! $match) {
                    return Response::error("GitHub repo {$entry['full_name']} not found in your accessible repos.");
                }
                $reposToAttach[] = ['match' => $match, 'primary' => (bool) ($entry['primary'] ?? false)];
            }
        }

        $project = DB::transaction(function () use ($team, $user, $validated) {
            return Project::create([
                'team_id' => $team->id,
                'created_by_id' => $user->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => ProjectStatus::Active,
            ]);
        });

        $user->switchProject($project);

        $attached = [];
        $webhookErrors = [];
        foreach ($reposToAttach as $item) {
            $match = $item['match'];
            $url = $match['html_url'].'.git';

            $repo = Repo::query()->where('workspace_id', $team->workspace_id)->where('url', $url)->first()
                ?? Repo::create([
                    'workspace_id' => $team->workspace_id,
                    'name' => $match['name'],
                    'provider' => RepoProvider::Github,
                    'url' => $url,
                    'default_branch' => $match['default_branch'],
                    'access_token' => $user->github_token,
                ]);

            if (! $repo->webhook_secret) {
                $result = $repo->installGithubWebhook();
                if (! $result['ok'] && $result['error'] !== null && ! str_contains($result['error'], 'admin:repo_hook')) {
                    $webhookErrors[] = "{$match['full_name']}: {$result['error']}";
                }
            }

            $project->attachRepo($repo, role: null, primary: $item['primary']);

            $attached[] = [
                'id' => $repo->id,
                'full_name' => $match['full_name'],
                'primary' => $item['primary'],
                'webhook_installed' => (bool) $repo->fresh()->webhook_secret,
            ];
        }

        return Response::json([
            'id' => $project->id,
            'team_id' => $project->team_id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status?->value,
            'is_current' => true,
            'attached_repos' => $attached,
            'webhook_errors' => $webhookErrors,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->integer()
                ->description('Team to create the project under. Defaults to the user’s current team. Caller must be Owner or Admin.'),
            'name' => $schema->string()->description('Project name.')->required(),
            'description' => $schema->string()->description('Project description. Markdown supported.'),
            'repos' => $schema->array()
                ->description('Optional GitHub repos to attach. Each entry: {full_name: "owner/name", primary?: bool}. At most one may be primary.'),
        ];
    }
}
