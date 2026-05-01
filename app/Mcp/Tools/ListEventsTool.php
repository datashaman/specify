<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Repo;
use App\Models\WebhookEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: list-events
 */
#[Description('List recent webhook events. Filter by repo_id (required unless project_id given). Newest first.')]
class ListEventsTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'list-events';

    /**
     * Handle the MCP tool invocation.
     */
    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $repoId = $request->integer('repo_id') ?: null;
        $projectId = $request->integer('project_id') ?: null;
        $limit = min(max((int) ($request->integer('limit') ?: 25), 1), 200);

        $accessible = $user->accessibleProjectIds();

        $repoIds = [];
        if ($repoId) {
            $repo = Repo::query()->find($repoId);
            if (! $repo) {
                return Response::error('Repo not found.');
            }
            $repoProjectIds = $repo->projects()->pluck('projects.id')->all();
            if (empty(array_intersect($accessible, $repoProjectIds))) {
                return Response::error('Repo not accessible.');
            }
            $repoIds = [$repo->id];
        } elseif ($projectId) {
            if (! $this->canAccessProject($user, $projectId)) {
                return Response::error('Project not accessible.');
            }
            $repoIds = Repo::query()
                ->whereHas('projects', fn ($q) => $q->where('projects.id', $projectId))
                ->pluck('id')
                ->all();
        } else {
            return Response::error('Provide repo_id or project_id.');
        }

        if (empty($repoIds)) {
            return Response::json(['count' => 0, 'events' => []]);
        }

        $events = WebhookEvent::query()
            ->whereIn('repo_id', $repoIds)
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'repo_id', 'provider', 'event', 'action', 'signature_valid', 'matched_run_id', 'created_at']);

        return Response::json([
            'count' => $events->count(),
            'events' => $events->map(fn (WebhookEvent $e) => [
                'id' => $e->id,
                'repo_id' => $e->repo_id,
                'provider' => $e->provider,
                'event' => $e->event,
                'action' => $e->action,
                'signature_valid' => (bool) $e->signature_valid,
                'matched_run_id' => $e->matched_run_id,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repo_id' => $schema->integer()->description('Repo to list events for.'),
            'project_id' => $schema->integer()->description('Or, list events across all repos in this project.'),
            'limit' => $schema->integer()->description('Max number of events (1–200, default 25).'),
        ];
    }
}
