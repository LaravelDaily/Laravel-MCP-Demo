<?php

use App\Enums\WorkspaceRole;
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

test('returns 401 for missing invalid and revoked MCP bearer tokens', function (string $kind) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = $user->createToken('revoked');
    $token->accessToken->delete();
    $headers = match ($kind) {
        'missing' => [],
        'invalid' => ['Authorization' => 'Bearer invalid'],
        'revoked' => ['Authorization' => 'Bearer '.$token->plainTextToken],
    };

    $this->postJson('/mcp/workspaces/'.$workspace->slug, mcpInitialize(), $headers)
        ->assertUnauthorized()->assertJsonMissingPath('result');
})->with(['missing', 'invalid', 'revoked']);

test('authenticates a real Sanctum token at the MCP endpoint', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = $user->createToken('client')->plainTextToken;

    $this->postJson('/mcp/workspaces/'.$workspace->slug, mcpInitialize(), ['Authorization' => 'Bearer '.$token])
        ->assertOk()->assertJsonPath('result.serverInfo.name', 'TeamBoard');
});

test('returns 404 for unknown and inaccessible MCP workspace slugs', function (string $kind) {
    $user = User::factory()->create();
    $foreign = Workspace::factory()->create(['name' => 'Secret workspace']);
    $token = $user->createToken('client')->plainTextToken;

    $this->postJson('/mcp/workspaces/'.($kind === 'unknown' ? 'unknown' : $foreign->slug), mcpInitialize(), ['Authorization' => 'Bearer '.$token])
        ->assertNotFound()->assertDontSee('Secret workspace');
})->with(['unknown', 'inaccessible']);

test('uses the MCP workspace URL instead of the browser session', function () {
    $user = User::factory()->create();
    $first = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $second = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $token = $user->createToken('client')->plainTextToken;

    $this->withSession([WorkspaceContext::SESSION_KEY => $first->id])
        ->postJson('/mcp/workspaces/'.$second->slug, mcpInitialize(), ['Authorization' => 'Bearer '.$token])->assertOk();
    expect(app(WorkspaceContext::class)->currentOrFail()->id)->toBe($second->id);

    Auth::forgetGuards();
    $this->postJson('/mcp/workspaces/'.$first->slug, mcpInitialize(), ['Authorization' => 'Bearer '.$token])->assertOk();
    expect(app(WorkspaceContext::class)->currentOrFail()->id)->toBe($first->id);
});

test('requires bearer authentication even with a browser session', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $this->actingAs($user)->postJson('/mcp/workspaces/'.$workspace->slug, mcpInitialize())->assertUnauthorized();
});

test('authenticates before resolving MCP workspace details', function () {
    $this->postJson('/mcp/workspaces/unknown', mcpInitialize())->assertUnauthorized();
});
