<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddAcceptanceCriterionTool;
use App\Mcp\Tools\AddGithubRepoToProjectTool;
use App\Mcp\Tools\AddStoryDependencyTool;
use App\Mcp\Tools\ApprovePlanTool;
use App\Mcp\Tools\ApproveStoryTool;
use App\Mcp\Tools\CreateFeatureTool;
use App\Mcp\Tools\CreatePlanTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateScenarioTool;
use App\Mcp\Tools\CreateStoryTool;
use App\Mcp\Tools\CurrentContextTool;
use App\Mcp\Tools\GenerateTasksTool;
use App\Mcp\Tools\GetFeatureTool;
use App\Mcp\Tools\GetPlanTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetRepoTool;
use App\Mcp\Tools\GetRunTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListEventsTool;
use App\Mcp\Tools\ListFeaturesTool;
use App\Mcp\Tools\ListPlansTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListReposTool;
use App\Mcp\Tools\ListRunsTool;
use App\Mcp\Tools\ListScenariosTool;
use App\Mcp\Tools\ListStoriesTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\RejectPlanTool;
use App\Mcp\Tools\RejectStoryTool;
use App\Mcp\Tools\RemoveProjectRepoTool;
use App\Mcp\Tools\RequestPlanChangesTool;
use App\Mcp\Tools\RequestStoryChangesTool;
use App\Mcp\Tools\SetCurrentPlanTool;
use App\Mcp\Tools\SetPrimaryRepoTool;
use App\Mcp\Tools\SetTasksTool;
use App\Mcp\Tools\StartRunTool;
use App\Mcp\Tools\SubmitPlanTool;
use App\Mcp\Tools\SubmitStoryTool;
use App\Mcp\Tools\SwitchProjectTool;
use App\Mcp\Tools\UpdateFeatureTool;
use App\Mcp\Tools\UpdatePlanTool;
use App\Mcp\Tools\UpdateScenarioTool;
use App\Mcp\Tools\UpdateStoryTool;
use App\Mcp\Tools\UpdateSubtaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Specify Server')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
Tools for managing Specify projects, features, stories, scenarios, and plans. The hierarchy is Project → Feature → Story → AcceptanceCriterion / Scenario → Plan → Task → Subtask. A story owns product framing plus acceptance criteria and scenarios. A story may also have one or more implementation plans; the current plan owns tasks, and tasks own subtasks. A story must belong to a feature, so call create-feature before create-story when no matching feature exists. Most tools default to the authenticated user's current project when project_id is omitted.

Voice and scope, very important:

- A FEATURE is a product owner's framing of a capability — what users get and why it matters. Not implementation detail.
- A STORY is a product contract for a unit of value. Use `actor`, `intent`, and `outcome` for "as a / I want / so that" framing where it fits. Keep implementation detail out.
- An ACCEPTANCE CRITERION is a short, atomic, observable rule. Do not put whole Given/When/Then scenarios here.
- A SCENARIO holds Given/When/Then behaviour examples.
- A PLAN is the implementation interpretation of a story.
- Plans have their own approval lifecycle. Story approval gates the product contract; current-plan approval gates execution.
- A TASK is an actionable work item under a plan. A SUBTASK is the executor-sized engineering step.

Never put schemas, class names, file paths, or migration steps in a feature or story description — that belongs in plans, tasks, or subtasks.

Markdown is supported in every description field.
TXT)]
class SpecifyServer extends Server
{
    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 200;

    protected array $tools = [
        CurrentContextTool::class,
        SwitchProjectTool::class,
        ListProjectsTool::class,
        GetProjectTool::class,
        CreateProjectTool::class,
        AddGithubRepoToProjectTool::class,
        RemoveProjectRepoTool::class,
        SetPrimaryRepoTool::class,
        ListFeaturesTool::class,
        GetFeatureTool::class,
        CreateFeatureTool::class,
        UpdateFeatureTool::class,
        ListStoriesTool::class,
        GetStoryTool::class,
        CreateStoryTool::class,
        UpdateStoryTool::class,
        AddAcceptanceCriterionTool::class,
        ListScenariosTool::class,
        CreateScenarioTool::class,
        UpdateScenarioTool::class,
        CreatePlanTool::class,
        GetPlanTool::class,
        ListPlansTool::class,
        UpdatePlanTool::class,
        SetCurrentPlanTool::class,
        SubmitPlanTool::class,
        ApprovePlanTool::class,
        RequestPlanChangesTool::class,
        RejectPlanTool::class,
        AddStoryDependencyTool::class,
        SubmitStoryTool::class,
        ApproveStoryTool::class,
        RequestStoryChangesTool::class,
        RejectStoryTool::class,
        ListTasksTool::class,
        GetTaskTool::class,
        GenerateTasksTool::class,
        SetTasksTool::class,
        UpdateTaskTool::class,
        UpdateSubtaskTool::class,
        ListRunsTool::class,
        GetRunTool::class,
        StartRunTool::class,
        ListReposTool::class,
        GetRepoTool::class,
        ListEventsTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
