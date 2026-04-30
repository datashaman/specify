<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth;
use App\Models\Plan;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new plan (revision) for a story, with its task graph. Version auto-increments per story. Sets the new plan as the story’s current plan unless set_current is false.')]
class CreatePlanTool extends Tool
{
    protected string $name = 'create-plan';

    public function handle(Request $request): Response
    {
        $user = Auth::resolve($request);
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate([
            'story_id' => ['required', 'integer'],
            'summary' => ['nullable', 'string'],
            'set_current' => ['nullable', 'boolean'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.position' => ['required', 'integer', 'min:1'],
            'tasks.*.name' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.depends_on' => ['nullable', 'array'],
            'tasks.*.depends_on.*' => ['integer', 'min:1'],
        ]);

        $story = Story::query()->with('feature')->find($validated['story_id']);
        if (! $story) {
            return Response::error('Story not found.');
        }

        if (! in_array($story->feature->project_id, $user->accessibleProjectIds(), true)) {
            return Response::error('Story not accessible.');
        }

        $positions = array_column($validated['tasks'], 'position');
        if (count($positions) !== count(array_unique($positions))) {
            return Response::error('Task positions must be unique within a plan.');
        }

        $plan = DB::transaction(function () use ($story, $validated) {
            $version = ($story->plans()->max('version') ?? 0) + 1;

            $plan = Plan::create([
                'story_id' => $story->id,
                'version' => $version,
                'summary' => $validated['summary'] ?? null,
            ]);

            $byPosition = [];
            foreach ($validated['tasks'] as $task) {
                $byPosition[$task['position']] = Task::create([
                    'plan_id' => $plan->id,
                    'position' => $task['position'],
                    'name' => $task['name'],
                    'description' => $task['description'] ?? null,
                ]);
            }

            foreach ($validated['tasks'] as $task) {
                foreach ($task['depends_on'] ?? [] as $dependsOnPosition) {
                    if (! isset($byPosition[$task['position']], $byPosition[$dependsOnPosition])) {
                        continue;
                    }
                    $byPosition[$task['position']]->addDependency($byPosition[$dependsOnPosition]);
                }
            }

            if ($validated['set_current'] ?? true) {
                $story->forceFill(['current_plan_id' => $plan->id])->save();
            }

            return $plan;
        });

        return Response::json([
            'id' => $plan->id,
            'story_id' => $plan->story_id,
            'version' => $plan->version,
            'summary' => $plan->summary,
            'status' => $plan->status?->value,
            'tasks_count' => $plan->tasks()->count(),
            'is_current' => $plan->id === $story->fresh()->current_plan_id,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'story_id' => $schema->integer()->description('Story this plan belongs to.')->required(),
            'summary' => $schema->string()->description('Optional plan summary.'),
            'set_current' => $schema->boolean()->description('Set this plan as the story’s current_plan. Defaults to true.'),
            'tasks' => $schema->array()
                ->description('Ordered task list. Each task: {position:int, name:string, description?:string, depends_on?:int[] of positions}. Positions must be unique within the plan.')
                ->required(),
        ];
    }
}
