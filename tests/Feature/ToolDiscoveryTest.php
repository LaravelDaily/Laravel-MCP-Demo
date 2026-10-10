<?php

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

const READ_TOOL_NAMES = ['list_tasks', 'list_projects', 'list_my_tasks', 'get_task', 'search_tasks', 'list_workspace_members'];

const CONTRIBUTOR_TOOL_NAMES = ['create_task', 'update_task', 'assign_task', 'move_task', 'add_comment'];

function discoverTools(string $token, Workspace $workspace): TestResponse
{
    return test()->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);
}

function callGuessedTool(string $token, Workspace $workspace, string $name, array $arguments): TestResponse
{
    return test()->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

test('discovers all tools for owners', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = mcpAccessToken($user);

    $response = discoverTools($token, $workspace);

    $response->assertOk();
    expect(array_column($response->json('result.tools'), 'name'))->toBe([...READ_TOOL_NAMES, ...CONTRIBUTOR_TOOL_NAMES, 'delete_task']);
});

test('hides delete_task from members', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Member cannot delete']);
    $comment = Comment::factory()->for($task)->create();
    $before = $task->fresh()->getAttributes();
    $token = mcpAccessToken($user);

    $discovery = discoverTools($token, $workspace);
    Auth::forgetGuards();
    $guessedDeletion = callGuessedTool($token, $workspace, 'delete_task', ['task_id' => $task->id]);

    $discovery->assertOk();
    expect(array_column($discovery->json('result.tools'), 'name'))->toBe([...READ_TOOL_NAMES, ...CONTRIBUTOR_TOOL_NAMES]);
    $guessedDeletion->assertBadRequest()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Tool [delete_task] not found.')
        ->assertJsonMissingPath('result');
    expect($task->fresh()->getAttributes())->toBe($before);
    $this->assertModelExists($comment);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(1);
});

test('hides every write tool from viewers', function (string $name, Closure $arguments) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $comment = Comment::factory()->for($task)->create();
    $before = $task->fresh()->getAttributes();
    $token = mcpAccessToken($user);

    $discovery = discoverTools($token, $workspace);
    Auth::forgetGuards();
    $guessedCall = callGuessedTool($token, $workspace, $name, $arguments($task));

    $discovery->assertOk();
    expect(array_column($discovery->json('result.tools'), 'name'))->toBe(READ_TOOL_NAMES);
    $guessedCall->assertBadRequest()
        ->assertJsonPath('error.message', 'Tool ['.$name.'] not found.')
        ->assertJsonMissingPath('result');
    expect($task->fresh()->getAttributes())->toBe($before);
    $this->assertModelExists($comment);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(1);
})->with([
    'create_task' => ['create_task', fn (Task $task): array => ['project_id' => $task->project_id, 'title' => 'Guessed task']],
    'update_task' => ['update_task', fn (Task $task): array => ['task_id' => $task->id, 'title' => 'Guessed title']],
    'assign_task' => ['assign_task', fn (Task $task): array => ['task_id' => $task->id, 'assignee_id' => null]],
    'move_task' => ['move_task', fn (Task $task): array => ['task_id' => $task->id, 'status' => 'done']],
    'add_comment' => ['add_comment', fn (Task $task): array => ['task_id' => $task->id, 'body' => 'Guessed comment']],
    'delete_task' => ['delete_task', fn (Task $task): array => ['task_id' => $task->id]],
]);

test('uses the selected workspaces role for tool discovery', function () {
    $user = User::factory()->create();
    $ownedWorkspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $viewedWorkspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $token = mcpAccessToken($user);

    $ownerDiscovery = discoverTools($token, $ownedWorkspace);
    Auth::forgetGuards();
    $viewerDiscovery = discoverTools($token, $viewedWorkspace);
    Auth::forgetGuards();
    $repeatedOwnerDiscovery = discoverTools($token, $ownedWorkspace);

    expect(array_column($ownerDiscovery->json('result.tools'), 'name'))->toBe([...READ_TOOL_NAMES, ...CONTRIBUTOR_TOOL_NAMES, 'delete_task']);
    expect(array_column($viewerDiscovery->json('result.tools'), 'name'))->toBe(READ_TOOL_NAMES);
    expect(array_column($repeatedOwnerDiscovery->json('result.tools'), 'name'))->toBe([...READ_TOOL_NAMES, ...CONTRIBUTOR_TOOL_NAMES, 'delete_task']);
});

test('updates discovery after a role change between requests', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = mcpAccessToken($user);
    $ownerDiscovery = discoverTools($token, $workspace);
    $workspace->memberships()->where('user_id', $user->id)->update(['role' => WorkspaceRole::Member->value]);
    Auth::forgetGuards();

    $memberDiscovery = discoverTools($token, $workspace);

    expect(array_column($ownerDiscovery->json('result.tools'), 'name'))->toContain('delete_task');
    expect(array_column($memberDiscovery->json('result.tools'), 'name'))->toBe([...READ_TOOL_NAMES, ...CONTRIBUTOR_TOOL_NAMES]);
});
