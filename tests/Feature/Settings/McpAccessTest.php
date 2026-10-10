<?php

use App\Enums\WorkspaceRole;
use App\Livewire\Settings\McpAccess;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Livewire\Livewire;

/**
 * Connect an MCP client by storing an access token and refresh token the
 * way a completed authorization-code exchange does.
 */
function connectMcpClient(User $user, string $name): Client
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, ['http://localhost:33418/callback'], confidential: false);
    $token = Token::forceCreate([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'client_id' => $client->id,
        'scopes' => ['mcp:use'],
        'revoked' => false,
        'expires_at' => now()->addHour(),
    ]);
    $token->refreshToken()->forceCreate([
        'id' => Str::random(80),
        'access_token_id' => $token->id,
        'revoked' => false,
        'expires_at' => now()->addDays(30),
    ]);

    return $client;
}

test('lists only the current users connected MCP clients', function () {
    $this->travelTo('2026-10-09 10:00:00');
    $user = User::factory()->create();
    connectMcpClient($user, 'Claude Code');
    connectMcpClient(User::factory()->create(), 'Foreign user client');
    $revoked = connectMcpClient($user, 'Disconnected client');
    Token::where('client_id', $revoked->id)->update(['revoked' => true]);

    $response = $this->actingAs($user)->get('/settings/mcp-access');

    $response->assertOk()
        ->assertSee('Claude Code')
        ->assertSee('2026-10-09 10:00 UTC')
        ->assertDontSee('Foreign user client')
        ->assertDontSee('Disconnected client')
        ->assertDontSee($user->tokens()->first()->id);
});

test('no longer offers copyable MCP tokens', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/mcp-access');

    $response->assertOk()
        ->assertSee('You have no connected AI clients.')
        ->assertDontSee('Create token');
    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});

test('disconnecting a client revokes its access and refresh tokens', function () {
    $user = User::factory()->create();
    $client = connectMcpClient($user, 'Client to disconnect');
    $kept = connectMcpClient($user, 'Client to keep');

    $component = Livewire::actingAs($user)->test(McpAccess::class)
        ->call('disconnect', $client->id);

    $component->assertHasNoErrors()
        ->assertDontSee('Client to disconnect')
        ->assertSee('Client to keep');
    $token = Token::where('client_id', $client->id)->sole();
    expect($token->revoked)->toBeTrue();
    expect($token->refreshToken->revoked)->toBeTrue();
    expect(Token::where('client_id', $kept->id)->sole()->revoked)->toBeFalse();
});

test('a disconnected client can no longer call the MCP endpoint', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $accessToken = mcpAccessToken($user);
    $clientId = $user->tokens()->sole()->client_id;
    Livewire::actingAs($user)->test(McpAccess::class)->call('disconnect', $clientId);
    Auth::logout();
    Auth::forgetGuards();

    $response = mcpJsonRpc($accessToken, $workspace, 'tools/list');

    $response->assertUnauthorized();
});

test('returns 404 when disconnecting another users client', function () {
    $user = User::factory()->create();
    $foreign = connectMcpClient(User::factory()->create(), 'Foreign client');

    $component = Livewire::actingAs($user)->test(McpAccess::class)
        ->call('disconnect', $foreign->id);

    $component->assertNotFound();
    expect(Token::where('client_id', $foreign->id)->sole()->revoked)->toBeFalse();
});

test('guests cannot disconnect MCP clients', function () {
    $user = User::factory()->create();
    $client = connectMcpClient($user, 'Guest boundary client');
    $page = $this->actingAs($user)->get('/settings/mcp-access');
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    Auth::logout();
    Auth::forgetGuards();

    $response = $this->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['method' => 'disconnect', 'params' => [$client->id]]],
        ]],
    ], ['X-Livewire' => 'true']);

    $response->assertUnauthorized();
    expect(Token::where('client_id', $client->id)->sole()->revoked)->toBeFalse();
});
