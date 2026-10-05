<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ProjectReviewPrompt;
use App\Mcp\Resources\ProjectInstructionsResource;
use App\Mcp\Tools\AddCommentTool;
use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListMyTasksTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ListWorkspaceMembersTool;
use App\Mcp\Tools\MoveTaskTool;
use App\Mcp\Tools\SearchTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource as McpResource;
use Laravel\Mcp\Server\Tool;

class TeamBoardServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ListTasksTool::class,
        ListProjectsTool::class,
        ListMyTasksTool::class,
        GetTaskTool::class,
        SearchTasksTool::class,
        ListWorkspaceMembersTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        AssignTaskTool::class,
        MoveTaskTool::class,
        AddCommentTool::class,
        DeleteTaskTool::class,
    ];

    /** @var array<int, class-string<McpResource>> */
    protected array $resources = [
        ProjectInstructionsResource::class,
    ];

    /** @var array<int, class-string<Prompt>> */
    protected array $prompts = [
        ProjectReviewPrompt::class,
    ];

    protected string $name = 'TeamBoard';

    protected string $version = '1.0.0';

    protected string $instructions = 'TeamBoard operates only within the workspace selected in the endpoint URL. Authentication identifies you; membership grants workspace access; policies control operations. Read tools return bounded workspace data. Write tools are listed only when your role in this workspace allows them: owners and members can create, update, assign, move, and comment; only owners can delete tasks. Deleting a task is permanent and also removes its comments. Read project instructions from the project://{project}/instructions resource template using a project ID from list_projects; instructions are guidance for the client and are not automatically executed. The project_review prompt returns review instructions for your client to follow with read tools; the server does not run the review.';
}
