<?php

namespace App\Mcp\Concerns;

use App\Mcp\Auth;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Scenario;
use App\Models\Story;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Helpers for the recurring "resolve acting user → fetch model → check
 * project access" pattern in MCP tools. Each helper returns either the
 * resolved model or a Response::error; callers type-narrow with `instanceof
 * Response`. Centralising the rule means every project-scoped tool
 * authorises the same way without spelling out the dance.
 */
trait ResolvesProjectAccess
{
    protected function resolveUser(Request $request): User|Response
    {
        return Auth::resolve($request) ?? Response::error('Authentication required.');
    }

    protected function resolveAccessibleStory(
        int $storyId,
        User $user,
        string $notFoundMessage = 'Story not found.',
        string $forbiddenMessage = 'Story not accessible.',
    ): Story|Response {
        $story = Story::query()->with('feature')->find($storyId);
        if (! $story) {
            return Response::error($notFoundMessage);
        }
        if (! $this->canAccessProject($user, (int) $story->feature->project_id)) {
            return Response::error($forbiddenMessage);
        }

        return $story;
    }

    protected function resolveAccessibleFeature(
        int $featureId,
        User $user,
        string $notFoundMessage = 'Feature not found.',
        string $forbiddenMessage = 'Feature not accessible.',
    ): Feature|Response {
        $feature = Feature::query()->find($featureId);
        if (! $feature) {
            return Response::error($notFoundMessage);
        }
        if (! $this->canAccessProject($user, (int) $feature->project_id)) {
            return Response::error($forbiddenMessage);
        }

        return $feature;
    }

    protected function resolveAccessibleProject(
        int $projectId,
        User $user,
        string $notFoundMessage = 'Project not found.',
        string $forbiddenMessage = 'Project not accessible.',
    ): Project|Response {
        $project = Project::query()->find($projectId);
        if (! $project) {
            return Response::error($notFoundMessage);
        }
        if (! $this->canAccessProject($user, (int) $project->getKey())) {
            return Response::error($forbiddenMessage);
        }

        return $project;
    }

    protected function resolveAccessibleScenario(
        int $scenarioId,
        User $user,
        string $notFoundMessage = 'Scenario not found.',
        string $forbiddenMessage = 'Scenario not accessible.',
    ): Scenario|Response {
        $scenario = Scenario::query()->with('story.feature')->find($scenarioId);
        if (! $scenario) {
            return Response::error($notFoundMessage);
        }
        if (! $this->canAccessProject($user, (int) $scenario->story->feature->project_id)) {
            return Response::error($forbiddenMessage);
        }

        return $scenario;
    }

    protected function resolveAccessiblePlan(
        int $planId,
        User $user,
        string $notFoundMessage = 'Plan not found.',
        string $forbiddenMessage = 'Plan not accessible.',
    ): Plan|Response {
        $plan = Plan::query()->with('story.feature')->find($planId);
        if (! $plan) {
            return Response::error($notFoundMessage);
        }
        if (! $this->canAccessProject($user, (int) $plan->story->feature->project_id)) {
            return Response::error($forbiddenMessage);
        }

        return $plan;
    }

    protected function canAccessProject(User $user, int $projectId): bool
    {
        return in_array($projectId, $user->accessibleProjectIds(), true);
    }
}
