<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

test('returns 429 after the per-user MCP request limit is exhausted', function () {
    $this->freezeTime();
    config(['teamboard.mcp_rate_limit_per_minute' => 60]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();
    $token = $user->createToken('Rate limited client')->plainTextToken;
    $methods = [
        ['initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Rate limit test', 'version' => '1.0.0']]],
        ['tools/list', []],
        ['tools/call', ['name' => 'list_tasks', 'arguments' => (object) []]],
    ];
    $allowedStatuses = [];
    for ($request = 0; $request < 60; $request++) {
        [$method, $params] = $methods[$request % 3];
        $allowedStatuses[] = mcpJsonRpc($token, $workspace, $method, $params)->status();
    }

    $response = mcpJsonRpc($token, $workspace, 'tools/call', ['name' => 'create_task', 'arguments' => ['project_id' => $project->id, 'title' => 'Throttled task']]);

    expect($allowedStatuses)->toBe(array_fill(0, 60, 200));
    $response->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')
        ->assertHeader('X-RateLimit-Limit', '60')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertJsonMissingPath('result');
    expect(Task::count())->toBe(0);
    $this->assertDatabaseCount('mcp_audit_logs', 0);
});

test('shares a users rate limit across workspace connections', function () {
    config(['teamboard.mcp_rate_limit_per_minute' => 2]);
    $user = User::factory()->create();
    $first = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $second = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $firstToken = $user->createToken('First workspace client')->plainTextToken;
    $secondToken = $user->createToken('Second workspace client')->plainTextToken;
    mcpJsonRpc($firstToken, $first, 'tools/list')->assertOk();
    Auth::forgetGuards();
    mcpJsonRpc($secondToken, $second, 'tools/list')->assertOk();
    Auth::forgetGuards();

    $firstResponse = mcpJsonRpc($firstToken, $first, 'tools/list');
    Auth::forgetGuards();
    $secondResponse = mcpJsonRpc($secondToken, $second, 'tools/list');

    $firstResponse->assertTooManyRequests();
    $secondResponse->assertTooManyRequests();
});

test('does not share rate limits between different users', function () {
    config(['teamboard.mcp_rate_limit_per_minute' => 1]);
    $limited = User::factory()->create();
    $other = User::factory()->create();
    $workspace = Workspace::factory()->withMember($limited, WorkspaceRole::Owner)->withMember($other, WorkspaceRole::Member)->create();
    $limitedToken = $limited->createToken('Exhausted client')->plainTextToken;
    $otherToken = $other->createToken('Independent client')->plainTextToken;
    mcpJsonRpc($limitedToken, $workspace, 'tools/list')->assertOk();
    mcpJsonRpc($limitedToken, $workspace, 'tools/list')->assertTooManyRequests();
    Auth::forgetGuards();

    $response = mcpJsonRpc($otherToken, $workspace, 'tools/list');

    $response->assertOk()
        ->assertJsonCount(11, 'result.tools');
});

test('resumes MCP access after the rate limit window', function () {
    $this->freezeTime();
    config(['teamboard.mcp_rate_limit_per_minute' => 1]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = $user->createToken('Patient client')->plainTextToken;
    mcpJsonRpc($token, $workspace, 'tools/list')->assertOk();
    $this->travel(59)->seconds();
    mcpJsonRpc($token, $workspace, 'tools/list')->assertTooManyRequests()->assertHeader('Retry-After', '1');
    $this->travel(1)->seconds();

    $response = mcpJsonRpc($token, $workspace, 'tools/list');

    $response->assertOk()
        ->assertJsonCount(12, 'result.tools');
});

test('authenticates before counting MCP requests against the limit', function () {
    config(['teamboard.mcp_rate_limit_per_minute' => 1]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = $user->createToken('Unaffected client')->plainTextToken;
    mcpJsonRpc('invalid', $workspace, 'tools/list')->assertUnauthorized();
    mcpJsonRpc('invalid', $workspace, 'tools/list')->assertUnauthorized();

    $response = mcpJsonRpc($token, $workspace, 'tools/list');

    $response->assertOk();
});
