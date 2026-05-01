<?php

namespace App\Mcp\Tools;

use App\Enums\RepoProvider;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Mcp\GithubRepoCatalog;
use App\Models\Repo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Pick a GitHub repo and attach it to a project. Mirrors the Repos page picker: creates the Repo with the user’s OAuth token, installs the webhook, and attaches. Set set_primary=true to mark it primary in the same call.')]
class AddGithubRepoToProjectTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'add-github-repo-to-project';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'full_name' => ['required', 'string'],
            'set_primary' => ['nullable', 'boolean'],
        ]);

        $projectId = $validated['project_id'] ?? $user->current_project_id;
        if (! $projectId) {
            return Response::error('No project_id provided and no current project set.');
        }

        $project = $this->resolveAccessibleProject($projectId, $user);
        if ($project instanceof Response) {
            return $project;
        }
        $project->load('team');

        if (! $user->canApproveInProject($project)) {
            return Response::error('You do not have manage rights in this project.');
        }

        if (! $user->github_token) {
            return Response::error('Cannot resolve GitHub repos without a connected GitHub OAuth session.');
        }

        $match = GithubRepoCatalog::findByFullName($user, $validated['full_name']);
        if (! $match) {
            return Response::error("GitHub repo {$validated['full_name']} not found in your accessible repos.");
        }

        $workspaceId = $project->team?->workspace_id;
        if (! $workspaceId) {
            return Response::error('Project has no workspace.');
        }

        $url = $match['html_url'].'.git';

        $repo = Repo::query()->where('workspace_id', $workspaceId)->where('url', $url)->first()
            ?? Repo::create([
                'workspace_id' => $workspaceId,
                'name' => $match['name'],
                'provider' => RepoProvider::Github,
                'url' => $url,
                'default_branch' => $match['default_branch'],
                'access_token' => $user->github_token,
            ]);

        $webhookError = null;
        if (! $repo->webhook_secret) {
            $result = $repo->installGithubWebhook();
            if (! $result['ok'] && $result['error'] !== null && ! str_contains($result['error'], 'admin:repo_hook')) {
                $webhookError = $result['error'];
            }
        }

        $project->attachRepo($repo, role: null, primary: (bool) ($validated['set_primary'] ?? false));

        return Response::json([
            'project_id' => $project->id,
            'repo' => [
                'id' => $repo->id,
                'full_name' => $match['full_name'],
                'url' => $repo->url,
                'default_branch' => $repo->default_branch,
                'is_primary' => (bool) ($validated['set_primary'] ?? false),
                'webhook_installed' => (bool) $repo->fresh()->webhook_secret,
            ],
            'webhook_error' => $webhookError,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project to attach to. Defaults to the user’s current project.'),
            'full_name' => $schema->string()->description('GitHub repo full name (e.g. "owner/repo").')->required(),
            'set_primary' => $schema->boolean()->description('Mark this repo as the project’s primary. Defaults to false.'),
        ];
    }
}
