<?php

use App\Models\AcceptanceCriterion;
use App\Models\Plan;
use App\Models\PlanApproval;
use App\Models\StoryApproval;
use App\Models\Task;
use App\Services\ExecutionService;
use Illuminate\Support\Facades\Schema;

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
        ->and($agent)->toContain("'acceptance_criterion_position' => \$schema->integer()->min(1),");
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
