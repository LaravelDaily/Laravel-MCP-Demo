<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Prompts\ProjectReviewPrompt;
use App\Mcp\Servers\TeamBoardServer;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Testing\TestResponse;

function reviewPromptAs(User $user, Workspace $workspace, array $arguments): TestResponse
{
    $server = TeamBoardServer::actingAs($user);
    app(WorkspaceContext::class)->setExplicitWorkspace($workspace);

    return $server->prompt(ProjectReviewPrompt::class, $arguments);
}

test('discovers project_review with a required project argument', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $token = mcpAccessToken($user);

    $response = mcpJsonRpc($token, $workspace, 'prompts/list');

    $response->assertOk()
        ->assertJsonCount(1, 'result.prompts')
        ->assertJsonPath('result.prompts.0.name', 'project_review')
        ->assertJsonPath('result.prompts.0.title', 'Project review')
        ->assertJsonPath('result.prompts.0.description', 'Review one project in the endpoint workspace, chosen by name or ID: read its instructions resource, inspect unfinished tasks with the read tools, and summarize overdue, high-priority, unassigned, and likely blocked work with three next actions. The server only generates these instructions; your client runs the review without changing data.')
        ->assertJsonPath('result.prompts.0.arguments', [[
            'name' => 'project',
            'description' => 'Name or ID of a project in this workspace (for example "Website Redesign", "website", or "12"). Names are matched case-insensitively, and part of a name works when it matches one project.',
            'required' => true,
        ]]);
});

test('generates a review prompt for the selected workspace project', function () {
    $this->travelTo('2026-10-05 23:30:00');
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create(['name' => 'Review workspace']);
    $project = Project::factory()->for($workspace)->create(['name' => 'Website Redesign']);
    $token = mcpAccessToken($user);

    $response = mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project' => 'Website Redesign']]);

    $response->assertOk()
        ->assertJsonCount(1, 'result.messages')
        ->assertJsonPath('result.messages.0.role', 'user')
        ->assertJsonPath('result.messages.0.content.type', 'text');
    expect($response->json('result.messages.0.content.text'))
        ->toContain('Review the TeamBoard project "Website Redesign" (project ID '.$project->id.') in the workspace "Review workspace".')
        ->toContain("Today's date is 2026-10-05 (UTC).")
        ->toContain('Read the project instructions resource project://'.$project->id.'/instructions')
        ->toContain('Use list_tasks with project_id '.$project->id.' and status "todo", then with status "in_progress"')
        ->toContain('until meta.page equals meta.last_page')
        ->toContain('Use get_task')
        ->toContain('Use search_tasks or list_workspace_members')
        ->toContain('Overdue tasks: unfinished tasks (status todo or in_progress) whose due_date is strictly before 2026-10-05. A task due today is not overdue, and done tasks are never overdue.')
        ->toContain('High-priority unfinished tasks: priority "high".')
        ->toContain('Unassigned unfinished tasks: assignee is null.')
        ->toContain('Likely blockers: infer them from task descriptions and comments')
        ->toContain('TeamBoard has no blocker status or field')
        ->toContain('A short summary')
        ->toContain('The next three actions')
        ->toContain('Do not create, edit, assign, move, comment on, or delete anything. Change data only if the user makes a separate request after the review.');
});

test('allows each workspace role to request a review prompt', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Role review project']);

    $response = reviewPromptAs($user, $workspace, ['project' => (string) $project->id]);

    $response->assertOk()
        ->assertSee(['"Role review project" (project ID '.$project->id.')', 'project://'.$project->id.'/instructions']);
})->with(WorkspaceRole::cases());

test('resolves the review project by ID or by name', function (Closure $argument) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Website Redesign']);
    Project::factory()->for($workspace)->create(['name' => 'Billing Improvements']);

    $response = reviewPromptAs($user, $workspace, ['project' => $argument($project)]);

    $response->assertOk()
        ->assertSee('"Website Redesign" (project ID '.$project->id.')');
})->with([
    'ID' => [fn (Project $project): string => (string) $project->id],
    'exact name' => [fn (Project $project): string => 'Website Redesign'],
    'name in another case with spaces around' => [fn (Project $project): string => '  website redesign '],
    'unique part of the name' => [fn (Project $project): string => 'website'],
]);

test('prefers an exact name over a partial match', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Website']);
    Project::factory()->for($workspace)->create(['name' => 'Website Redesign']);

    $response = reviewPromptAs($user, $workspace, ['project' => 'website']);

    $response->assertOk()
        ->assertSee('"Website" (project ID '.$project->id.')');
});

test('rejects missing ambiguous and foreign review project arguments', function (Closure $arguments, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    Project::factory()->for($workspace)->create(['name' => 'Billing API']);
    Project::factory()->for($workspace)->create(['name' => 'Billing Dashboard']);
    $foreign = Project::factory()->create(['name' => 'Secret foreign project', 'instructions' => 'Secret foreign instructions']);
    $inactive = Project::factory()->for($other)->create(['name' => 'Inactive project', 'instructions' => 'Inactive instructions']);
    $before = [$foreign->fresh()->getAttributes(), $inactive->fresh()->getAttributes()];

    $response = reviewPromptAs($user, $workspace, $arguments($foreign, $inactive));

    $response->assertHasErrors([$message])
        ->assertDontSee(['Secret foreign instructions', 'Inactive instructions', 'project://']);
    expect([$foreign->fresh()->getAttributes(), $inactive->fresh()->getAttributes()])->toBe($before);
    expect(Project::count())->toBe(4);
})->with([
    'missing' => [fn (Project $foreign, Project $inactive): array => [], 'The project argument is required. Use list_projects to see the projects in this workspace.'],
    'blank' => [fn (Project $foreign, Project $inactive): array => ['project' => ''], 'The project argument is required. Use list_projects to see the projects in this workspace.'],
    'unknown name' => [fn (Project $foreign, Project $inactive): array => ['project' => 'Marketing'], 'No project in the current workspace matches "Marketing". Use list_projects to see the projects in this workspace.'],
    'wildcard only' => [fn (Project $foreign, Project $inactive): array => ['project' => '%'], 'No project in the current workspace matches "%". Use list_projects to see the projects in this workspace.'],
    'ambiguous part of a name' => [fn (Project $foreign, Project $inactive): array => ['project' => 'billing'], 'More than one project in the current workspace matches "billing". Use a longer name or the project ID from list_projects.'],
    'foreign name' => [fn (Project $foreign, Project $inactive): array => ['project' => 'Secret foreign project'], 'No project in the current workspace matches "Secret foreign project". Use list_projects to see the projects in this workspace.'],
    'other accessible workspace name' => [fn (Project $foreign, Project $inactive): array => ['project' => 'Inactive project'], 'No project in the current workspace matches "Inactive project". Use list_projects to see the projects in this workspace.'],
    'unsupported argument' => [fn (Project $foreign, Project $inactive): array => ['project' => 'Billing API', 'workspace_id' => '1'], 'The workspace_id field is not supported here.'],
    'legacy project_id argument' => [fn (Project $foreign, Project $inactive): array => ['project_id' => '1'], 'The project_id field is not supported here.'],
]);

test('returns the same protocol error for foreign and missing review projects', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $foreign = Project::factory()->create(['name' => 'Secret foreign project']);
    $token = mcpAccessToken($user);

    $foreignResponse = mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project' => (string) $foreign->id]]);
    Auth::forgetGuards();
    $missingResponse = mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project' => '99999']]);

    $foreignResponse->assertBadRequest()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'No project in the current workspace matches "'.$foreign->id.'". Use list_projects to see the projects in this workspace.')
        ->assertJsonMissingPath('result')
        ->assertDontSee('Secret foreign project');
    $missingResponse->assertBadRequest()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'No project in the current workspace matches "99999". Use list_projects to see the projects in this workspace.');
});
