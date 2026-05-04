<?php

use App\Enums\PlanStatus;
use App\Mcp\Servers\SpecifyServer;
use App\Mcp\Tools\GenerateTasksTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\SetTasksTool;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\Project;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ExecutionService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Instructions;

test('agent run authorization keeps story generation and plan execution distinct', function () {
    $taskGeneration = new ReflectionMethod(ExecutionService::class, 'dispatchTaskGeneration');
    $subtaskExecution = new ReflectionMethod(ExecutionService::class, 'dispatchSubtaskExecution');

    expect(namedParameterType($taskGeneration, 'approval'))->toBe(StoryApproval::class)
        ->and(namedParameterType($subtaskExecution, 'approval'))->toBe(PlanApproval::class);
});

test('load-bearing docs describe the current planning model', function () {
    $readme = file_get_contents(base_path('README.md'));
    $agents = file_get_contents(base_path('AGENTS.md'));
    $adrIndex = file_get_contents(base_path('docs/adr/README.md'));
    $prompt = file_get_contents(base_path('prompts/tasks-generator.md'));
    $agent = file_get_contents(base_path('app/Ai/Agents/TasksGenerator.php'));

    expect($readme)->toContain('Story -> AcceptanceCriterion / Scenario -> Plan -> Task -> Subtask')
        ->and($readme)->toContain('Story approval gates the product contract; current Plan approval gates execution')
        ->and($agents)->toContain('Tasks attach to Plans; Subtasks live under Tasks')
        ->and($agents)->toContain('Resolve Story through `Task -> Plan -> Story`')
        ->and($adrIndex)->toContain('Story and current Plan are the approval gates')
        ->and($prompt)->toContain('Shape Tasks around coherent implementation work')
        ->and($agent)->toContain('may span acceptance criteria, scenarios, or shared enabling work');
});

test('task generation prompt allows cross-cutting plan tasks', function () {
    $prompt = file_get_contents(base_path('prompts/tasks-generator.md'));
    $agent = file_get_contents(base_path('app/Ai/Agents/TasksGenerator.php'));

    expect($prompt)->toContain('Shape Tasks around coherent implementation work')
        ->and($prompt)->toContain('Leave it absent when the Task is cross-cutting')
        ->and($agent)->toContain('may span acceptance criteria, scenarios, or shared enabling work')
        ->and($agent)->toContain("'acceptance_criterion_position' =>")
        ->and($agent)->not->toContain("'acceptance_criterion_position' => \$schema->integer()->min(1)->required()");
});

test('mcp instructions and planning tool descriptions speak in current-plan terms', function () {
    $instructions = serverAttribute(SpecifyServer::class, Instructions::class);

    expect($instructions)->toContain('Project → Feature → Story → AcceptanceCriterion / Scenario → Plan → Task → Subtask')
        ->and($instructions)->toContain('the current plan owns tasks')
        ->and($instructions)->toContain('current-plan approval gates execution');

    $descriptions = [
        GenerateTasksTool::class => descriptionFor(GenerateTasksTool::class),
        SetTasksTool::class => descriptionFor(SetTasksTool::class),
        ListTasksTool::class => descriptionFor(ListTasksTool::class),
        GetTaskTool::class => descriptionFor(GetTaskTool::class),
        GetStoryTool::class => descriptionFor(GetStoryTool::class),
    ];

    expect($descriptions[GenerateTasksTool::class])->toContain('fresh current Plan')
        ->and($descriptions[SetTasksTool::class])->toContain('Replace the story\'s current implementation Plan')
        ->and($descriptions[SetTasksTool::class])->toContain('For Approved stories, the fresh Plan starts PendingApproval')
        ->and($descriptions[SetTasksTool::class])->toContain('for non-Approved stories, it stays Draft')
        ->and($descriptions[ListTasksTool::class])->toContain('Tasks in a story\'s current Plan')
        ->and($descriptions[GetTaskTool::class])->toContain('Plan-owned Task')
        ->and($descriptions[GetStoryTool::class])->toContain('current Plan metadata')
        ->and($descriptions[SetTasksTool::class])->toContain('may link to an optional acceptance_criterion_id and/or scenario_id')
        ->and($descriptions[ListTasksTool::class])->toContain('Each entry includes plan_id');
});

test('list-tasks exposes current plan ownership on every task row', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $supersededTask = Task::factory()->forCurrentPlanOf($story)->create(['position' => 1, 'name' => 'old plan task']);
    Subtask::factory()->for($supersededTask)->create(['position' => 1]);
    $supersededPlan = $supersededTask->plan;

    $currentPlan = Plan::factory()->for($story)->create([
        'version' => 2,
        'status' => PlanStatus::Draft,
    ]);
    $story->forceFill(['current_plan_id' => $currentPlan->getKey()])->save();
    $currentTask = Task::factory()->for($currentPlan)->create(['position' => 1, 'name' => 'current plan task']);
    Subtask::factory()->for($currentTask)->create(['position' => 1]);

    $this->actingAs($user);

    $response = (new ListTasksTool)->handle(new Request(['story_id' => $story->getKey()]));
    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['story_id'])->toBe($story->getKey())
        ->and($payload['current_plan_id'])->toBe($currentPlan->getKey())
        ->and($payload['tasks'])->toHaveCount(1)
        ->and($payload['tasks'][0]['id'])->toBe($currentTask->getKey())
        ->and($payload['tasks'][0]['plan_id'])->toBe($currentPlan->getKey())
        ->and(collect($payload['tasks'])->pluck('id')->all())->not->toContain($supersededTask->getKey())
        ->and($supersededPlan->getKey())->not->toBe($currentPlan->getKey());
});

function namedParameterType(ReflectionMethod $method, string $parameterName): string
{
    foreach ($method->getParameters() as $parameter) {
        if ($parameter->getName() !== $parameterName) {
            continue;
        }

        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            return '';
        }

        return $type->getName();
    }

    return '';
}

/**
 * @param  class-string  $class
 */
function descriptionFor(string $class): string
{
    return serverAttribute($class, Description::class);
}

/**
 * @template T of object
 *
 * @param  class-string  $class
 * @param  class-string<T>  $attribute
 */
function serverAttribute(string $class, string $attribute): string
{
    $attributes = (new ReflectionClass($class))->getAttributes($attribute);

    return $attributes === [] ? '' : $attributes[0]->newInstance()->value;
}
