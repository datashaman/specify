<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddAcceptanceCriterionTool;
use App\Mcp\Tools\AddStoryDependencyTool;
use App\Mcp\Tools\ApproveStoryTool;
use App\Mcp\Tools\CreateFeatureTool;
use App\Mcp\Tools\CreateStoryTool;
use App\Mcp\Tools\CurrentContextTool;
use App\Mcp\Tools\GetFeatureTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\ListFeaturesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListStoriesTool;
use App\Mcp\Tools\RejectStoryTool;
use App\Mcp\Tools\RequestStoryChangesTool;
use App\Mcp\Tools\SubmitStoryTool;
use App\Mcp\Tools\SwitchProjectTool;
use App\Mcp\Tools\UpdateFeatureTool;
use App\Mcp\Tools\UpdateStoryTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Specify Server')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
Tools for managing Specify projects, features, and stories. The hierarchy is project → feature → story. A story must belong to a feature, so call create_feature before create_story when no matching feature exists. Most tools default to the authenticated user’s current project when project_id is omitted.
TXT)]
class SpecifyServer extends Server
{
    protected array $tools = [
        CurrentContextTool::class,
        SwitchProjectTool::class,
        ListProjectsTool::class,
        GetProjectTool::class,
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
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
