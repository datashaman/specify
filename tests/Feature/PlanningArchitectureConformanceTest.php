<?php

use App\Mcp\Servers\SpecifyServer;
use App\Mcp\Tools\GenerateTasksTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\SetTasksTool;
use App\Models\AcceptanceCriterion;
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
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Instructions;

test('tasks are owned by plans and do not carry direct story ownership', function () {
    expect(Schema::hasColumn('tasks', 'plan_id'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'story_id'))->toBeFalse()
        ->and(method_exists(Task::class, 'story'))->toBeFalse()
        ->and(Task::make()->plan()->getRelated())->toBeInstanceOf(Plan::class);
});

test('acceptance criteria use statement as the only persisted content field', function () {
    $columns = Schema::getColumnListing('acceptance_criteria');

    expect($columns)->toContain('statement')
        ->and($columns)->not->toContain('criterion')
        ->and((new AcceptanceCriterion)->getFillable())->toContain('statement')
        ->and((new AcceptanceCriterion)->getFillable())->not->toContain('criterion');
});

test('agent run authorization keeps story generation and plan execution distinct', function () {
    $taskGeneration = new ReflectionMethod(ExecutionService::class, 'dispatchTaskGeneration');
    $subtaskExecution = new ReflectionMethod(ExecutionService::class, 'dispatchSubtaskExecution');

    expect(namedParameterType($taskGeneration, 'approval'))->toBe(StoryApproval::class)
        ->and(namedParameterType($subtaskExecution, 'approval'))->toBe(PlanApproval::class);
});

test('load-bearing docs do not reintroduce the retired planning model', function () {
    $files = [
        'AGENTS.md',
        'CHANGELOG.md',
        'CONTEXT.md',
        'CONTRIBUTING.md',
        'README.md',
        'docs/adr/README.md',
        'prompts/tasks-generator.md',
        'app/Ai/Agents/TasksGenerator.php',
    ];

    $forbidden = [
        'Story is the only approval gate',
        'Plan is retired',
        'Plan retired',
        'Tasks attach to Stories',
        'tasks.story_id',
        'Task::story',
        'Produce exactly one Task per Acceptance Criterion',
        'One Task per Acceptance Criterion, each',
    ];

    foreach ($files as $file) {
        $contents = file_get_contents(base_path($file));

        foreach ($forbidden as $phrase) {
            expect($contents, "{$file} must not contain stale phrase [{$phrase}]")
                ->not->toContain($phrase);
        }
    }
});

test('task generation prompt allows cross-cutting plan tasks', function () {
    $prompt = file_get_contents(base_path('prompts/tasks-generator.md'));
    $agent = file_get_contents(base_path('app/Ai/Agents/TasksGenerator.php'));

    expect($prompt)->toContain('Shape Tasks around coherent implementation work')
        ->and($prompt)->toContain('Leave it absent when the Task is cross-cutting')
        ->and($agent)->toContain('do not force one Task per Acceptance Criterion')
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
        ->and($descriptions[ListTasksTool::class])->toContain('Tasks in a story\'s current Plan')
        ->and($descriptions[GetTaskTool::class])->toContain('Plan-owned Task')
        ->and($descriptions[GetStoryTool::class])->toContain('current Plan metadata');

    foreach ($descriptions as $description) {
        expect($description)->not->toContain('tasks attached to a story')
            ->and($description)->not->toContain('task list for a story')
            ->and($description)->not->toContain('one acceptance_criterion_id');
    }
});

test('list-tasks exposes current plan ownership on every task row', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $feature = Feature::factory()->for($project)->create();
    $story = Story::factory()->for($feature)->create();
    $task = Task::factory()->forStory($story)->create(['position' => 1]);
    Subtask::factory()->for($task)->create(['position' => 1]);

    $this->actingAs($user);

    $response = (new ListTasksTool)->handle(new Request(['story_id' => $story->getKey()]));
    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['story_id'])->toBe($story->getKey())
        ->and($payload['current_plan_id'])->toBe($task->plan_id)
        ->and($payload['tasks'][0]['id'])->toBe($task->getKey())
        ->and($payload['tasks'][0]['plan_id'])->toBe($task->plan_id);
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
