<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Support\Facades\Auth;

function mcpInitialize(): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'TeamBoard tests', 'version' => '1.0.0'],
    ]];
}

function mcpListTasks(): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
        'name' => 'list_tasks',
        'arguments' => (object) [],
    ]];
}

test('returns 401 for missing invalid and revoked MCP bearer tokens', function (Closure $headers) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = $user->createToken('Revoked transport test token');
    $token->accessToken->delete();

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, mcpInitialize(), $headers($token->plainTextToken));

    $response->assertUnauthorized()
        ->assertJsonMissingPath('result');
})->with([
    'missing' => [fn (string $token): array => []],
    'invalid' => [fn (string $token): array => ['Authorization' => 'Bearer invalid']],
    'revoked' => [fn (string $token): array => ['Authorization' => 'Bearer '.$token]],
]);

test('authenticates a real Sanctum token at the MCP endpoint', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Bearer identity permitted task']);
    Task::factory()->create(['title' => 'Bearer identity foreign task']);
    $token = $user->createToken('Transport client')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, mcpListTasks(), ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonCount(1, 'result.structuredContent.data')
        ->assertJsonPath('result.structuredContent.data.0.id', $task->id)
        ->assertDontSee('Bearer identity foreign task');
});

test('returns 404 for unknown and inaccessible MCP workspace slugs', function (Closure $slug) {
    $user = User::factory()->create();
    $foreign = Workspace::factory()->create(['name' => 'Secret workspace']);
    $token = $user->createToken('Transport client')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$slug($foreign), mcpInitialize(), ['Authorization' => 'Bearer '.$token]);

    $response->assertNotFound()
        ->assertDontSee('Secret workspace');
})->with([
    'unknown' => [fn (Workspace $foreign): string => 'unknown'],
    'inaccessible' => [fn (Workspace $foreign): string => $foreign->slug],
]);

test('uses the MCP workspace URL instead of the browser session', function () {
    $user = User::factory()->create();
    $first = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $second = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $firstTask = Task::factory()->for(Project::factory()->for($first))->create(['title' => 'First workspace task']);
    $secondTask = Task::factory()->for(Project::factory()->for($second))->create(['title' => 'Second workspace task']);
    $token = $user->createToken('Multi-workspace transport client')->plainTextToken;

    $response = $this->withSession([WorkspaceContext::SESSION_KEY => $first->id])
        ->postJson('/mcp/workspaces/'.$second->slug, mcpListTasks(), ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonCount(1, 'result.structuredContent.data')
        ->assertJsonPath('result.structuredContent.data.0.id', $secondTask->id)
        ->assertDontSee('First workspace task');

    Auth::forgetGuards();

    $response = $this->postJson('/mcp/workspaces/'.$first->slug, mcpListTasks(), ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonCount(1, 'result.structuredContent.data')
        ->assertJsonPath('result.structuredContent.data.0.id', $firstTask->id)
        ->assertDontSee('Second workspace task');
});

test('requires bearer authentication even with a browser session', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $response = $this->actingAs($user)->postJson('/mcp/workspaces/'.$workspace->slug, mcpInitialize());

    $response->assertUnauthorized();
});

test('authenticates before resolving MCP workspace details', function () {
    $response = $this->postJson('/mcp/workspaces/unknown', mcpInitialize());

    $response->assertUnauthorized();
});
