<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddAcceptanceCriterionTool;
use App\Mcp\Tools\AddGithubRepoToProjectTool;
use App\Mcp\Tools\AddStoryDependencyTool;
use App\Mcp\Tools\ApproveStoryTool;
use App\Mcp\Tools\CreateFeatureTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateStoryTool;
use App\Mcp\Tools\CurrentContextTool;
use App\Mcp\Tools\GenerateTasksTool;
use App\Mcp\Tools\GetFeatureTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetRepoTool;
use App\Mcp\Tools\GetRunTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListEventsTool;
use App\Mcp\Tools\ListFeaturesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListReposTool;
use App\Mcp\Tools\ListRunsTool;
use App\Mcp\Tools\ListStoriesTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\RejectStoryTool;
use App\Mcp\Tools\RemoveProjectRepoTool;
use App\Mcp\Tools\RequestStoryChangesTool;
use App\Mcp\Tools\SetPrimaryRepoTool;
use App\Mcp\Tools\SetTasksTool;
use App\Mcp\Tools\StartRunTool;
use App\Mcp\Tools\SubmitStoryTool;
use App\Mcp\Tools\SwitchProjectTool;
use App\Mcp\Tools\UpdateFeatureTool;
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
Tools for managing Specify projects, features, and stories. The hierarchy is project → feature → story → tasks (one per acceptance criterion) → subtasks (the engineering steps the executor runs). A story must belong to a feature, so call create-feature before create-story when no matching feature exists. Most tools default to the authenticated user's current project when project_id is omitted.

Voice and scope, very important:

- A FEATURE is a product owner's framing of a capability — what users get and why it matters. Not implementation detail.
- A STORY is a product owner's framing of a unit of value — typically "as a {role}, I {want}, so that {outcome}." Acceptance criteria describe observable behaviour. Not implementation detail.
- A TASK is the engineering contract for delivering one acceptance criterion. SUBTASKS are the engineering breakdown of a task; the executor runs one subtask at a time. The PLAN is the collective term for a story's tasks. Use generate-tasks to have the planning agent draft the task list for an Approved story (one task per AC, each with 1+ subtasks). Use set-tasks to overwrite the plan in one shot, or update-task / update-subtask for surgical edits. NEVER put schemas, class names, file paths, or migration steps in a feature or story description — that belongs in tasks/subtasks.

Generating or editing the plan of an Approved story automatically resets it to PendingApproval — story approval is the only gate before execution, and humans get a chance to review the plan before it runs. Exception (ADR-0005): the executor may append follow-up subtasks to the parent Task during a running approved Subtask without resetting approval, because intent and existing subtasks are unchanged. The diff-review surface (PR) catches any unwanted growth.

If a description starts drifting into "create table X with columns Y" or "add a Z service that does W", stop — that belongs in subtasks, not in the story. Rewrite the story in product-owner voice and capture the engineering breakdown via set-tasks.

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
