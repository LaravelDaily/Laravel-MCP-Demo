<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;

const OAUTH_REDIRECT_URI = 'http://localhost:33418/callback';

/**
 * Register a public client the way an MCP client does (RFC 7591).
 */
function registerOAuthClient(string $name = 'Claude Code'): string
{
    $response = test()->postJson('/oauth/register', [
        'client_name' => $name,
        'redirect_uris' => [OAUTH_REDIRECT_URI],
    ]);

    return $response->json('client_id');
}

/**
 * Open the authorization page with a PKCE S256 challenge for the verifier.
 */
function requestAuthorization(string $clientId, string $verifier): TestResponse
{
    return test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => OAUTH_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'client-state',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]));
}

/**
 * Approve the pending authorization and return the code from the redirect.
 */
function approveAuthorization(string $clientId): string
{
    $response = test()->post('/oauth/authorize', [
        'client_id' => $clientId,
        'auth_token' => session('authToken'),
    ]);

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['code'];
}

function exchangeAuthorizationCode(string $clientId, string $code, string $verifier): TestResponse
{
    return test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => OAUTH_REDIRECT_URI,
        'code_verifier' => $verifier,
        'code' => $code,
    ]);
}

/**
 * Run the whole browser authorization for the user and return the code.
 */
function authorizeAs(User $user, string $clientId, string $verifier): string
{
    test()->actingAs($user);
    requestAuthorization($clientId, $verifier)->assertOk();
    $code = approveAuthorization($clientId);
    Auth::logout();
    Auth::forgetGuards();

    return $code;
}

function oauthListTasks(string $accessToken, Workspace $workspace): TestResponse
{
    Auth::forgetGuards();

    return mcpJsonRpc($accessToken, $workspace, 'tools/call', ['name' => 'list_tasks', 'arguments' => (object) []]);
}

test('advertises OAuth discovery when an MCP request is unauthenticated', function () {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);

    $challenge = $this->postJson('/mcp/workspaces/acme', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => (object) []]);

    $challenge->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/workspaces/acme').'"');

    $resource = $this->getJson('/.well-known/oauth-protected-resource/mcp/workspaces/acme');

    $resource->assertOk()
        ->assertExactJson([
            'resource' => url('/mcp/workspaces/acme'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['mcp:use'],
        ]);

    $server = $this->getJson('/.well-known/oauth-authorization-server');

    $server->assertOk()
        ->assertJsonPath('issuer', url('/'))
        ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
        ->assertJsonPath('token_endpoint', url('/oauth/token'))
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('scopes_supported', ['mcp:use']);
});

test('registers an allowed public MCP OAuth client', function () {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => [OAUTH_REDIRECT_URI],
    ]);

    $response->assertCreated()
        ->assertJsonPath('redirect_uris', [OAUTH_REDIRECT_URI])
        ->assertJsonPath('scope', 'mcp:use')
        ->assertJsonPath('token_endpoint_auth_method', 'none');
    $client = Client::findOrFail($response->json('client_id'));
    expect($client->name)->toBe('Claude Code');
    expect($client->confidential())->toBeFalse();
    expect($client->hasGrantType('authorization_code'))->toBeTrue();
});

test('rejects MCP OAuth clients with disallowed redirect URIs', function (string $redirectUri) {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Unknown client',
        'redirect_uris' => [$redirectUri],
    ]);

    $response->assertBadRequest()
        ->assertJsonPath('error', 'invalid_redirect_uri');
    expect(Client::count())->toBe(0);
})->with([
    'foreign domain' => ['https://attacker.example/callback'],
    'unlisted custom scheme' => ['cursor://anysphere.cursor-retrieval/oauth/callback'],
]);

test('redirects guests to login before showing the consent screen', function () {
    $clientId = registerOAuthClient();

    $response = requestAuthorization($clientId, Str::random(64));

    $response->assertRedirect(route('login'));
});

test('shows the consent screen and issues no code until the user approves', function () {
    $user = User::factory()->create(['email' => 'alice@example.com']);
    $clientId = registerOAuthClient('Claude Code');

    $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => OAUTH_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]));

    $response->assertOk()
        ->assertSee('Authorize Claude Code')
        ->assertSee('alice@example.com')
        ->assertSee('http://localhost');
    $this->assertDatabaseCount('oauth_auth_codes', 0);
});

test('denying consent redirects back with an error and grants no access', function () {
    $user = User::factory()->create();
    $clientId = registerOAuthClient();
    $this->actingAs($user);
    requestAuthorization($clientId, Str::random(64));

    $response = $this->delete('/oauth/authorize', [
        'client_id' => $clientId,
        'auth_token' => session('authToken'),
    ]);

    $response->assertRedirectContains(OAUTH_REDIRECT_URI.'?')
        ->assertRedirectContains('error=access_denied')
        ->assertRedirectContains('state=client-state');
    $this->assertDatabaseCount('oauth_auth_codes', 0);
    $this->assertDatabaseCount('oauth_access_tokens', 0);
});

test('requires a PKCE code challenge from public clients', function () {
    $user = User::factory()->create();
    $clientId = registerOAuthClient();

    $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => OAUTH_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'mcp:use',
    ]));

    $response->assertBadRequest();
    $this->assertDatabaseCount('oauth_auth_codes', 0);
});

test('exchanges an authorization code using a valid PKCE verifier', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Task visible through OAuth']);
    Task::factory()->create(['title' => 'Foreign task hidden from OAuth']);
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);
    $code = authorizeAs($user, $clientId, $verifier);

    $tokens = exchangeAuthorizationCode($clientId, $code, $verifier);

    $tokens->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_in', 3600)
        ->assertJsonStructure(['access_token', 'refresh_token']);

    $response = oauthListTasks($tokens->json('access_token'), $workspace);

    $response->assertOk()
        ->assertJsonCount(1, 'result.structuredContent.data')
        ->assertJsonPath('result.structuredContent.data.0.id', $task->id)
        ->assertDontSee('Foreign task hidden from OAuth');
});

test('rejects invalid PKCE verifiers and reused authorization codes', function () {
    $user = User::factory()->create();
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);
    $code = authorizeAs($user, $clientId, $verifier);

    $wrongVerifier = exchangeAuthorizationCode($clientId, $code, Str::random(64));
    $validExchange = exchangeAuthorizationCode($clientId, $code, $verifier);
    $reusedCode = exchangeAuthorizationCode($clientId, $code, $verifier);

    $wrongVerifier->assertBadRequest()
        ->assertJsonPath('error', 'invalid_grant');
    $validExchange->assertOk();
    $reusedCode->assertBadRequest()
        ->assertJsonPath('error', 'invalid_grant');
    $this->assertDatabaseCount('oauth_access_tokens', 1);
});

test('refreshes an expiring access token and retires the previous one', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);
    $tokens = exchangeAuthorizationCode($clientId, authorizeAs($user, $clientId, $verifier), $verifier);

    $refreshed = $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $tokens->json('refresh_token'),
    ]);

    $refreshed->assertOk();
    oauthListTasks($refreshed->json('access_token'), $workspace)->assertOk();
    oauthListTasks($tokens->json('access_token'), $workspace)->assertUnauthorized();
});
