<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateFeatureTool;
use App\Mcp\Tools\CreateStoryTool;
use App\Mcp\Tools\ListFeaturesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListStoriesTool;
use App\Mcp\Tools\UpdateFeatureTool;
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
        ListProjectsTool::class,
        ListFeaturesTool::class,
        CreateFeatureTool::class,
        UpdateFeatureTool::class,
        ListStoriesTool::class,
        CreateStoryTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
