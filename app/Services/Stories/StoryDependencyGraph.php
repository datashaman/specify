<?php

namespace App\Services\Stories;

use App\Enums\StoryStatus;
use App\Models\Feature;
use App\Models\Story;
use InvalidArgumentException;

class StoryDependencyGraph
{
    public function addDependency(Story $story, Story $dependency): void
    {
        if ($story->is($dependency)) {
            throw new InvalidArgumentException('A story cannot depend on itself.');
        }

        if ($this->workspaceId($story) !== $this->workspaceId($dependency)) {
            throw new InvalidArgumentException('Story dependencies must live in the same workspace.');
        }

        if ($this->dependsOnTransitively($dependency, $story)) {
            throw new InvalidArgumentException('Adding this dependency would create a cycle.');
        }

        $story->dependencies()->syncWithoutDetaching([$dependency->getKey()]);
    }

    public function dependsOnTransitively(Story $story, Story $candidate): bool
    {
        $visited = [];
        $stack = [$story->getKey()];

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $deps = Story::find($id)?->dependencies()->pluck('stories.id')->all() ?? [];
            foreach ($deps as $depId) {
                if ($depId === $candidate->getKey()) {
                    return true;
                }
                $stack[] = $depId;
            }
        }

        return false;
    }

    public function isReady(Story $story): bool
    {
        return $story->dependencies()
            ->where('status', '!=', StoryStatus::Done->value)
            ->doesntExist();
    }

    public function workspaceId(Story $story): ?int
    {
        return Feature::query()
            ->join('projects', 'projects.id', '=', 'features.project_id')
            ->join('teams', 'teams.id', '=', 'projects.team_id')
            ->whereKey($story->feature_id)
            ->value('teams.workspace_id');
    }
}
