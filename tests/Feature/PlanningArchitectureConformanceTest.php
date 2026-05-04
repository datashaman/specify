<?php

use App\Ai\Agents\ReviewResponder;
use App\Ai\Agents\TasksGenerator;
use App\Enums\PlanStatus;
use App\Mcp\Servers\SpecifyServer;
use App\Mcp\Tools\ApprovePlanTool;
use App\Mcp\Tools\ApproveStoryTool;
use App\Mcp\Tools\CreateStoryTool;
use App\Mcp\Tools\GenerateTasksTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListActivityTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\RejectPlanTool;
use App\Mcp\Tools\RequestPlanChangesTool;
use App\Mcp\Tools\RequestStoryChangesTool;
use App\Mcp\Tools\SetTasksTool;
use App\Mcp\Tools\SubmitPlanTool;
use App\Mcp\Tools\UpdateStoryTool;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\Project;
use App\Models\Repo;
use App\Models\Scenario;
use App\Models\Story;
use App\Models\StoryApproval;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\ExecutionService;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Tool;

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
        ->and(file_get_contents(base_path('prompts/README.md')))->toContain('review-responder.md')
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

test('agent prompts describe current plan ownership', function () {
    $tasksGenerator = file_get_contents(base_path('prompts/tasks-generator.md'));
    $subtaskExecutor = file_get_contents(base_path('prompts/subtask-executor.md'));
    $reviewResponder = file_get_contents(base_path('prompts/review-responder.md'));

    expect($tasksGenerator)->toContain('Story product contract')
        ->and($tasksGenerator)->toContain('Plan-owned Tasks')
        ->and($tasksGenerator)->toContain('Tasks belong to the generated Plan, not directly to the Story')
        ->and($subtaskExecutor)->toContain('approved a Story and its')
        ->and($subtaskExecutor)->toContain('current Plan; your job is to execute one Subtask from that Plan')
        ->and($subtaskExecutor)->toContain('execute one Subtask from that Plan')
        ->and($reviewResponder)->toContain('parent Task and current Plan')
        ->and($subtaskExecutor)->not->toContain('task'.' list');
});

test('tasks generator prompt includes scenarios supplied to the planner', function () {
    $story = makeStory();
    $criterion = $story->acceptanceCriteria()->firstOrFail();
    Scenario::factory()->forCriterion($criterion)->create([
        'position' => 1,
        'name' => 'Checkout happy path',
        'given_text' => 'Given a cart with billable items',
        'when_text' => 'When the customer checks out',
        'then_text' => 'Then the payment is captured',
        'notes' => 'Use the primary payment provider.',
    ]);

    $prompt = (new TasksGenerator($story))->buildPrompt();

    expect($prompt)->toContain('Scenarios (position. Given / When / Then):')
        ->and($prompt)->toContain('1. Checkout happy path (AC #1)')
        ->and($prompt)->toContain('Given: Given a cart with billable items')
        ->and($prompt)->toContain('Then: Then the payment is captured')
        ->and($prompt)->toContain('acceptance criteria and scenarios above');
});

test('review responder prompt includes current plan context', function () {
    $story = Story::factory()->create();
    $plan = Plan::factory()->for($story)->create([
        'version' => 2,
        'name' => 'Reviewable implementation plan',
        'summary' => 'Respond to review without changing the product contract.',
    ]);
    $story->forceFill(['current_plan_id' => $plan->getKey()])->save();
    $task = Task::factory()->for($plan)->create([
        'position' => 1,
        'name' => 'Address review feedback',
    ]);
    $subtask = Subtask::factory()->for($task)->create([
        'position' => 1,
        'name' => 'Patch reviewed code',
        'description' => 'Apply focused review fixes.',
    ]);

    $prompt = (new ReviewResponder(
        subtask: $subtask,
        pullRequestNumber: 86,
        reviewSummary: '',
        comments: [],
        workingBranch: 'cleanup/mcp-prompt-contract',
    ))->buildPrompt();

    expect($prompt)->toContain("Current Plan #{$plan->getKey()} (version 2")
        ->and($prompt)->toContain('Plan name: Reviewable implementation plan')
        ->and($prompt)->toContain('Respond to review without changing the product contract.')
        ->and($prompt)->toContain('Parent Task #1: Address review feedback');
});

test('mcp instructions and planning tool descriptions speak in current plan terms', function () {
    $instructions = serverAttribute(SpecifyServer::class, Instructions::class);

    expect($instructions)->toContain('Project → Feature → Story → AcceptanceCriterion / Scenario → Plan → Task → Subtask')
        ->and($instructions)->toContain('the current plan owns tasks')
        ->and($instructions)->toContain('current plan approval gates execution');

    $descriptions = [
        GenerateTasksTool::class => descriptionFor(GenerateTasksTool::class),
        SetTasksTool::class => descriptionFor(SetTasksTool::class),
        ListTasksTool::class => descriptionFor(ListTasksTool::class),
        GetTaskTool::class => descriptionFor(GetTaskTool::class),
        GetStoryTool::class => descriptionFor(GetStoryTool::class),
        SubmitPlanTool::class => descriptionFor(SubmitPlanTool::class),
        ApprovePlanTool::class => descriptionFor(ApprovePlanTool::class),
        RejectPlanTool::class => descriptionFor(RejectPlanTool::class),
        RequestPlanChangesTool::class => descriptionFor(RequestPlanChangesTool::class),
        ApproveStoryTool::class => descriptionFor(ApproveStoryTool::class),
        RequestStoryChangesTool::class => descriptionFor(RequestStoryChangesTool::class),
    ];

    expect($descriptions[GenerateTasksTool::class])->toContain('fresh current Plan')
        ->and($descriptions[SetTasksTool::class])->toContain('Replace the story\'s current implementation Plan')
        ->and($descriptions[SetTasksTool::class])->toContain('For Approved stories, the fresh Plan starts PendingApproval')
        ->and($descriptions[SetTasksTool::class])->toContain('for non-Approved stories, it stays Draft')
        ->and($descriptions[ListTasksTool::class])->toContain('Tasks in a story\'s current Plan')
        ->and($descriptions[GetTaskTool::class])->toContain('Plan-owned Task')
        ->and($descriptions[GetStoryTool::class])->toContain('current Plan metadata')
        ->and($descriptions[SetTasksTool::class])->toContain('may link to an optional acceptance_criterion_id and/or scenario_id')
        ->and($descriptions[ListTasksTool::class])->toContain('Each entry includes plan_id')
        ->and($descriptions[SubmitPlanTool::class])->toContain('current plan for approval')
        ->and($descriptions[ApprovePlanTool::class])->toContain('current plan')
        ->and($descriptions[RejectPlanTool::class])->toContain('current plan')
        ->and($descriptions[RequestPlanChangesTool::class])->toContain('current plan')
        ->and($descriptions[ApproveStoryTool::class])->toContain('story product contract')
        ->and($descriptions[RequestStoryChangesTool::class])->toContain('story product contract');
});

test('mcp story schemas reserve implementation detail for plans tasks and subtasks', function () {
    $createStorySchema = schemaDescriptionsFor(CreateStoryTool::class);
    $updateStorySchema = schemaDescriptionsFor(UpdateStoryTool::class);

    expect($createStorySchema['description'])->toContain('Plans, Tasks, and Subtasks')
        ->and($createStorySchema['notes'])->toContain('Plans, Tasks, and Subtasks')
        ->and($updateStorySchema['description'])->toContain('Plans, Tasks, and Subtasks')
        ->and($updateStorySchema['notes'])->toContain('Plans, Tasks, and Subtasks')
        ->and($createStorySchema['description'])->not->toContain('tasks/'.'subtasks')
        ->and($createStorySchema['notes'])->not->toContain('tasks/'.'subtasks');
});

test('mcp activity tool is the public project activity surface', function () {
    expect(descriptionFor(ListActivityTool::class))->toContain('project activity')
        ->and(toolNameFor(ListActivityTool::class))->toBe('list-activity');

    $serverTools = (new ReflectionClass(SpecifyServer::class))->getDefaultProperties()['tools'];

    expect($serverTools)->toContain(ListActivityTool::class)
        ->and(collect($serverTools)->map(fn (string $tool) => class_basename($tool))->all())->not->toContain('List'.'EventsTool');
});

test('list-activity returns project activity entries', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $team = Team::factory()->for($workspace)->create();
    $team->addMember($user);
    $project = Project::factory()->for($team)->create();
    $repo = Repo::factory()->for($workspace)->create();
    $project->attachRepo($repo);

    WebhookEvent::create([
        'repo_id' => $repo->getKey(),
        'provider' => 'github',
        'event' => 'pull_request',
        'action' => 'opened',
        'delivery_id' => 'delivery-activity-1',
        'signature_valid' => true,
        'payload' => ['number' => 123],
    ]);

    $this->actingAs($user);

    $response = app(ListActivityTool::class)->handle(new Request([
        'project_id' => $project->getKey(),
    ]));
    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toHaveKeys(['count', 'activity'])
        ->and($payload['count'])->toBe(1)
        ->and($payload['activity'][0]['event'])->toBe('pull_request')
        ->and($payload['activity'][0]['action'])->toBe('opened');
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
 * @param  class-string<Tool>  $class
 * @return array<string, string>
 */
function schemaDescriptionsFor(string $class): array
{
    $tool = app($class);
    $schemas = $tool->schema(new JsonSchemaTypeFactory);

    return collect($schemas)
        ->map(fn ($schema) => $schema->toArray()['description'] ?? '')
        ->all();
}

/**
 * @param  class-string<Tool>  $class
 */
function toolNameFor(string $class): string
{
    $property = (new ReflectionClass($class))->getProperty('name');

    return $property->getDefaultValue();
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
