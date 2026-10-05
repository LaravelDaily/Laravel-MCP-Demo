<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Resources\ProjectInstructionsResource;
use App\Mcp\Servers\TeamBoardServer;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Support\Facades\Auth;

test('discovers the project instructions resource template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    Project::factory()->for($workspace)->create();
    $token = $user->createToken('Resource discovery')->plainTextToken;

    $response = mcpJsonRpc($token, $workspace, 'resources/templates/list');

    $response->assertOk()
        ->assertJsonCount(1, 'result.resourceTemplates')
        ->assertJsonPath('result.resourceTemplates.0.uriTemplate', 'project://{project}/instructions')
        ->assertJsonPath('result.resourceTemplates.0.name', 'project_instructions')
        ->assertJsonPath('result.resourceTemplates.0.title', 'Project instructions')
        ->assertJsonPath('result.resourceTemplates.0.mimeType', 'application/json')
        ->assertJsonPath('result.resourceTemplates.0.description', 'Guidance for working on one project in the endpoint workspace, addressed by project ID (find IDs with list_projects). Returns JSON with project { id, name, description } and nullable instructions. Instructions are guidance for the client; the server does not execute them.');
});

test('does not list each project as a static resource', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    Project::factory()->count(2)->for($workspace)->create();
    $token = $user->createToken('Static resource discovery')->plainTextToken;

    $response = mcpJsonRpc($token, $workspace, 'resources/list');

    $response->assertOk()
        ->assertJsonPath('result.resources', []);
});

test('reads project instructions for every workspace role', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $project = Project::factory()->for($workspace)->create([
        'name' => 'Guided project',
        'description' => 'Project description',
        'instructions' => "Acceptance criteria are required.\nBugs need reproduction steps.",
    ]);
    Project::factory()->for($workspace)->create(['instructions' => 'Sibling project instructions']);
    $token = $user->createToken('Resource reader')->plainTextToken;
    $uri = 'project://'.$project->id.'/instructions';

    $response = mcpJsonRpc($token, $workspace, 'resources/read', ['uri' => $uri]);

    $response->assertOk()
        ->assertJsonCount(1, 'result.contents')
        ->assertJsonPath('result.contents.0.uri', $uri)
        ->assertJsonPath('result.contents.0.mimeType', 'application/json')
        ->assertDontSee('Sibling project instructions');
    expect(json_decode($response->json('result.contents.0.text'), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'project' => ['id' => $project->id, 'name' => 'Guided project', 'description' => 'Project description'],
        'instructions' => "Acceptance criteria are required.\nBugs need reproduction steps.",
    ]);
})->with(WorkspaceRole::cases());

test('returns nullable instructions for a project without guidance', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Unguided project', 'description' => null, 'instructions' => null]);
    $server = TeamBoardServer::actingAs($user);
    app(WorkspaceContext::class)->setExplicitWorkspace($workspace);

    $response = $server->resource(ProjectInstructionsResource::class, ['project' => $project->id]);

    $response->assertOk()
        ->assertSee('{"project":{"id":'.$project->id.',"name":"Unguided project","description":null},"instructions":null}');
});

test('rejects foreign missing and malformed project resource URIs', function (Closure $uri, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $foreign = Project::factory()->create(['name' => 'Secret foreign project', 'description' => 'Secret foreign description', 'instructions' => 'Secret foreign instructions']);
    $inactive = Project::factory()->for($other)->create(['name' => 'Inactive project', 'description' => 'Inactive description', 'instructions' => 'Inactive instructions']);
    $token = $user->createToken('Resource denial')->plainTextToken;

    $response = mcpJsonRpc($token, $workspace, 'resources/read', ['uri' => $uri($foreign, $inactive)]);

    $response->assertBadRequest()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', $message)
        ->assertJsonMissingPath('result')
        ->assertDontSee(['Secret foreign', 'Inactive project', 'Inactive description', 'Inactive instructions']);
})->with([
    'foreign' => [fn (Project $foreign, Project $inactive): string => 'project://'.$foreign->id.'/instructions', 'The project was not found in the current workspace.'],
    'other accessible workspace' => [fn (Project $foreign, Project $inactive): string => 'project://'.$inactive->id.'/instructions', 'The project was not found in the current workspace.'],
    'missing' => [fn (Project $foreign, Project $inactive): string => 'project://99999/instructions', 'The project was not found in the current workspace.'],
    'non-numeric' => [fn (Project $foreign, Project $inactive): string => 'project://abc/instructions', 'The project was not found in the current workspace.'],
    'zero' => [fn (Project $foreign, Project $inactive): string => 'project://0/instructions', 'The project was not found in the current workspace.'],
    'leading zero' => [fn (Project $foreign, Project $inactive): string => 'project://0'.$foreign->id.'/instructions', 'The project was not found in the current workspace.'],
    'negative' => [fn (Project $foreign, Project $inactive): string => 'project://-'.$foreign->id.'/instructions', 'The project was not found in the current workspace.'],
    'outside the template' => [fn (Project $foreign, Project $inactive): string => 'project://1/instructions/extra', 'Resource [project://1/instructions/extra] not found.'],
]);

test('does not mutate data when reading resources or requesting prompts', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create(['instructions' => 'Keep tasks small.']);
    $task = Task::factory()->for($project)->assignedTo($user)->create();
    $comment = Comment::factory()->for($task)->for($user, 'author')->create();
    $before = [$project->fresh()->getAttributes(), $task->fresh()->getAttributes(), $comment->fresh()->getAttributes()];
    $token = $user->createToken('Read-only context client')->plainTextToken;

    $resource = mcpJsonRpc($token, $workspace, 'resources/read', ['uri' => 'project://'.$project->id.'/instructions']);
    Auth::forgetGuards();
    $prompt = mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project_id' => (string) $project->id]]);

    $resource->assertOk()
        ->assertJsonPath('result.contents.0.mimeType', 'application/json');
    $prompt->assertOk()
        ->assertJsonCount(1, 'result.messages')
        ->assertDontSee(['create_task', 'update_task', 'assign_task', 'move_task', 'add_comment', 'delete_task']);
    expect([$project->fresh()->getAttributes(), $task->fresh()->getAttributes(), $comment->fresh()->getAttributes()])->toBe($before);
    expect(Project::count())->toBe(1);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(1);
});
