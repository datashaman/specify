<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Services\Plans\CurrentPlanSelector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Set a story\'s current plan. The plan must belong to the same accessible story.')]
class SetCurrentPlanTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'set-current-plan';

    public function handle(Request $request, CurrentPlanSelector $currentPlans): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'plan_id' => ['required', 'integer'],
        ]);

        $story = $this->resolveAccessibleStory($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        $plan = $this->resolveAccessiblePlan($validated['plan_id'], $user);
        if ($plan instanceof Response) {
            return $plan;
        }

        try {
            $currentPlans->setCurrent($story, $plan);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $story->refresh();

        return Response::json([
            'story_id' => $story->id,
            'current_plan' => [
                'id' => $plan->id,
                'version' => $plan->version,
                'name' => $plan->name,
                'status' => $plan->status?->value,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story whose current plan should be set.')->required(),
            'plan_id' => $schema->integer()->description('Plan to set as current. Must belong to the story.')->required(),
        ];
    }
}
