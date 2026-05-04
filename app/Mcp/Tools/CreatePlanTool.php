<?php

namespace App\Mcp\Tools;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use App\Models\Story;
use App\Services\Plans\CurrentPlanSelector;
use App\Services\Plans\PlanVersionAllocator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a plan under a story. Plans hold implementation summary, design notes, risks, assumptions, and status.')]
class CreatePlanTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'create-plan';

    public function handle(Request $request, CurrentPlanSelector $currentPlans, PlanVersionAllocator $planVersions): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'design_notes' => ['nullable', 'string'],
            'implementation_notes' => ['nullable', 'string'],
            'risks' => ['nullable', 'string'],
            'assumptions' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_column(PlanSource::cases(), 'value'))],
            'source_label' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(PlanStatus::cases(), 'value'))],
            'set_current' => ['nullable', 'boolean'],
        ]);

        $story = $this->resolveAccessibleStory($validated['story_id'], $user);
        if ($story instanceof Response) {
            return $story;
        }

        $plan = $planVersions->withNextVersion($story, function (int $nextVersion, Story $story) use ($validated, $currentPlans) {
            $plan = $story->plans()->create([
                'version' => $nextVersion,
                'revision' => 1,
                'name' => $validated['name'],
                'summary' => $validated['summary'] ?? null,
                'design_notes' => $validated['design_notes'] ?? null,
                'implementation_notes' => $validated['implementation_notes'] ?? null,
                'risks' => $validated['risks'] ?? null,
                'assumptions' => $validated['assumptions'] ?? null,
                'source' => isset($validated['source']) ? PlanSource::from($validated['source']) : PlanSource::Human,
                'source_label' => $validated['source_label'] ?? null,
                'status' => isset($validated['status']) ? PlanStatus::from($validated['status']) : PlanStatus::Draft,
            ]);

            if (($validated['set_current'] ?? false) === true) {
                $currentPlans->setCurrent($story, $plan);
            }

            return $plan;
        });

        return Response::json([
            'id' => $plan->id,
            'story_id' => $plan->story_id,
            'version' => $plan->version,
            'revision' => $plan->revision,
            'name' => $plan->name,
            'summary' => $plan->summary,
            'design_notes' => $plan->design_notes,
            'implementation_notes' => $plan->implementation_notes,
            'risks' => $plan->risks,
            'assumptions' => $plan->assumptions,
            'source' => $plan->source?->value,
            'source_label' => $plan->source_label,
            'status' => $plan->status?->value,
            'is_current' => (int) $story->fresh()->current_plan_id === (int) $plan->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        $statuses = array_column(PlanStatus::cases(), 'value');
        $sources = array_column(PlanSource::cases(), 'value');

        return [
            'story_id' => $schema->integer()->description('Story this plan belongs to.')->required(),
            'name' => $schema->string()->description('Plan name. Required.')->required(),
            'summary' => $schema->string()->description('Plan summary. Markdown supported.'),
            'design_notes' => $schema->string()->description('Design notes for the plan. Markdown supported.'),
            'implementation_notes' => $schema->string()->description('Implementation notes for the plan. Markdown supported.'),
            'risks' => $schema->string()->description('Known risks. Markdown supported.'),
            'assumptions' => $schema->string()->description('Assumptions behind the plan. Markdown supported.'),
            'source' => $schema->string()->description('Plan source. One of: '.implode(', ', $sources)),
            'source_label' => $schema->string()->description('Optional source label, e.g. OpenSpec or imported file name.'),
            'status' => $schema->string()->description('Plan status. One of: '.implode(', ', $statuses)),
            'set_current' => $schema->boolean()->description('When true, set this plan as the story\'s current plan.'),
        ];
    }
}
