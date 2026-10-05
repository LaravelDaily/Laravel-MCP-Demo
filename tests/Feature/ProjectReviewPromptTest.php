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
    $token = $user->createToken('Prompt discovery')->plainTextToken;

    $response = mcpJsonRpc($token, $workspace, 'prompts/list');

    $response->assertOk()
        ->assertJsonCount(1, 'result.prompts')
        ->assertJsonPath('result.prompts.0.name', 'project_review')
        ->assertJsonPath('result.prompts.0.title', 'Project review')
        ->assertJsonPath('result.prompts.0.description', 'Review one project in the endpoint workspace: read its instructions resource, inspect unfinished tasks with the read tools, and summarize overdue, high-priority, unassigned, and likely blocked work with three next actions. The server only generates these instructions; your client runs the review without changing data.')
        ->assertJsonPath('result.prompts.0.arguments', [[
            'name' => 'project_id',
            'description' => 'ID of a project in this workspace, as returned by list_projects (for example "12").',
            'required' => true,
        ]]);
});

test('generates a review prompt for the selected workspace project', function () {
    $this->travelTo('2026-10-05 23:30:00');
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create(['name' => 'Review workspace']);
    $project = Project::factory()->for($workspace)->create(['name' => 'Website Redesign']);
    $token = $user->createToken('Prompt client')->plainTextToken;

    $response = mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project_id' => (string) $project->id]]);

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

    $response = reviewPromptAs($user, $workspace, ['project_id' => (string) $project->id]);

    $response->assertOk()
        ->assertSee(['"Role review project" (project ID '.$project->id.')', 'project://'.$project->id.'/instructions']);
})->with(WorkspaceRole::cases());

test('rejects missing invalid and foreign review project arguments', function (Closure $arguments, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $foreign = Project::factory()->create(['name' => 'Secret foreign project', 'instructions' => 'Secret foreign instructions']);
    $inactive = Project::factory()->for($other)->create(['name' => 'Inactive project', 'instructions' => 'Inactive instructions']);
    $before = [$foreign->fresh()->getAttributes(), $inactive->fresh()->getAttributes()];

    $response = reviewPromptAs($user, $workspace, $arguments($foreign, $inactive));

    $response->assertHasErrors([$message])
        ->assertDontSee(['Secret foreign', 'Inactive project', 'Inactive instructions', 'project://']);
    expect([$foreign->fresh()->getAttributes(), $inactive->fresh()->getAttributes()])->toBe($before);
    expect(Project::count())->toBe(2);
})->with([
    'missing' => [fn (Project $foreign, Project $inactive): array => [], 'The project_id argument is required. Use list_projects to find a project ID.'],
    'blank' => [fn (Project $foreign, Project $inactive): array => ['project_id' => ''], 'The project_id argument is required. Use list_projects to find a project ID.'],
    'non-numeric' => [fn (Project $foreign, Project $inactive): array => ['project_id' => 'abc'], 'The project_id must be a positive whole-number project ID.'],
    'zero' => [fn (Project $foreign, Project $inactive): array => ['project_id' => '0'], 'The project_id must be a positive whole-number project ID.'],
    'decimal' => [fn (Project $foreign, Project $inactive): array => ['project_id' => '1.5'], 'The project_id must be a positive whole-number project ID.'],
    'foreign' => [fn (Project $foreign, Project $inactive): array => ['project_id' => (string) $foreign->id], 'The project must belong to the current workspace.'],
    'other accessible workspace' => [fn (Project $foreign, Project $inactive): array => ['project_id' => (string) $inactive->id], 'The project must belong to the current workspace.'],
    'missing ID' => [fn (Project $foreign, Project $inactive): array => ['project_id' => '99999'], 'The project must belong to the current workspace.'],
    'unsupported argument' => [fn (Project $foreign, Project $inactive): array => ['project_id' => (string) $foreign->id, 'workspace_id' => '1'], 'The workspace_id field is not supported here.'],
]);

test('returns the same protocol error for foreign and missing review projects', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $foreign = Project::factory()->create(['name' => 'Secret foreign project']);
    $token = $user->createToken('Prompt denial')->plainTextToken;

    $foreignResponse = mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project_id' => (string) $foreign->id]]);
    Auth::forgetGuards();
    $missingResponse = mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project_id' => '99999']]);

    $foreignResponse->assertBadRequest()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'The project must belong to the current workspace.')
        ->assertJsonMissingPath('result')
        ->assertDontSee('Secret foreign project');
    expect($missingResponse->json('error'))->toBe($foreignResponse->json('error'));
});
