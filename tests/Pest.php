<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Send one authenticated JSON-RPC request to a workspace MCP endpoint.
 *
 * @param  array<string, mixed>  $params
 */
function mcpJsonRpc(string $token, Workspace $workspace, string $method, array $params = []): TestResponse
{
    return test()->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params,
    ], ['Authorization' => 'Bearer '.$token]);
}

/**
 * Issue a real Passport access token with the MCP scope for the given user.
 * Tests that are not about the OAuth flow itself use this shortcut instead
 * of the full authorization-code exchange.
 */
function mcpAccessToken(User $user): string
{
    $hasPersonalAccessClient = Client::query()->get()
        ->contains(fn (Client $client): bool => $client->hasGrantType('personal_access'));

    if (! $hasPersonalAccessClient) {
        app(ClientRepository::class)->createPersonalAccessGrantClient('TeamBoard test client', 'users');
    }

    return $user->createToken('MCP test token', [Registrar::OAUTH_SCOPE])->accessToken;
}
