<?php

namespace App\Mcp\Tools;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Mcp\Concerns\ResolvesProjectAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a plan. Any of: name, summary, design_notes, implementation_notes, risks, assumptions, source, source_label, status.')]
class UpdatePlanTool extends Tool
{
    use ResolvesProjectAccess;

    protected string $name = 'update-plan';

    public function handle(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'design_notes' => ['nullable', 'string'],
            'implementation_notes' => ['nullable', 'string'],
            'risks' => ['nullable', 'string'],
            'assumptions' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_column(PlanSource::cases(), 'value'))],
            'source_label' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(PlanStatus::cases(), 'value'))],
        ]);

        $plan = $this->resolveAccessiblePlan($validated['plan_id'], $user);
        if ($plan instanceof Response) {
            return $plan;
        }

        $project = $plan->story?->feature?->project;
        $changes = [];
        $structuralChange = false;

        foreach (['name', 'summary', 'design_notes', 'implementation_notes', 'risks', 'assumptions', 'source_label'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
                $structuralChange = true;
            }
        }

        if (isset($validated['source'])) {
            $changes['source'] = PlanSource::from($validated['source']);
            $structuralChange = true;
        }
        if (isset($validated['status'])) {
            if (! $project || ! $user->canApproveInProject($project)) {
                return Response::error('You do not have approver rights to set plan status directly.');
            }
            $changes['status'] = PlanStatus::from($validated['status']);
        }

        if ($changes === []) {
            return Response::error('Provide at least one field to update.');
        }

        $plan->fill($changes)->save();

        if ($structuralChange && (int) $plan->story->current_plan_id === (int) $plan->id) {
            $plan->reopenForApproval();
        }

        $plan->refresh();

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
            'is_current' => (int) $plan->story->current_plan_id === (int) $plan->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        $statuses = array_column(PlanStatus::cases(), 'value');
        $sources = array_column(PlanSource::cases(), 'value');

        return [
            'plan_id' => $schema->integer()->description('Plan to update.')->required(),
            'name' => $schema->string()->description('New plan name.'),
            'summary' => $schema->string()->description('New plan summary. Markdown supported.'),
            'design_notes' => $schema->string()->description('New design notes. Markdown supported.'),
            'implementation_notes' => $schema->string()->description('New implementation notes. Markdown supported.'),
            'risks' => $schema->string()->description('New risks. Markdown supported.'),
            'assumptions' => $schema->string()->description('New assumptions. Markdown supported.'),
            'source' => $schema->string()->description('New plan source. One of: '.implode(', ', $sources)),
            'source_label' => $schema->string()->description('New source label.'),
            'status' => $schema->string()->description('New plan status. One of: '.implode(', ', $statuses)),
        ];
    }
}
