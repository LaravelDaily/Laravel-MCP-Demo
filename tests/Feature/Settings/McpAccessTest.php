<?php

use App\Livewire\Settings\McpAccess;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

test('creates a named MCP token for the authenticated user', function () {
    $user = User::factory()->create();
    $foreign = User::factory()->create()->createToken('Foreign client');

    $component = Livewire::actingAs($user)->test(McpAccess::class)
        ->set('name', '  Claude Code  ')
        ->call('createToken');

    $component->assertHasNoErrors()
        ->assertSee('Copy your token now')
        ->assertDontSee('Foreign client');
    preg_match('/\d+\|[a-zA-Z0-9]+/', $component->html(), $matches);
    expect($matches)->toHaveCount(1);
    $plaintext = $matches[0];
    preg_match('/<textarea\b[^>]*>(.*?)<\/textarea>/s', $component->html(), $textarea);
    expect(trim(html_entity_decode($textarea[1], ENT_QUOTES)))->toBe($plaintext);
    $token = $user->tokens()->sole();
    expect($token->name)->toBe('Claude Code');
    expect($token->token)->toBe(hash('sha256', explode('|', $plaintext, 2)[1]));
    expect($token->expires_at)->toBeNull();
    $component->assertDontSee($token->token);

    $component->call('$refresh');

    $component->assertDontSee($plaintext)
        ->assertDontSee($token->token);

    $response = $this->get('/settings/mcp-access');

    $response->assertOk()
        ->assertDontSee($plaintext)
        ->assertDontSee($token->token)
        ->assertDontSee($foreign->accessToken->token);
});

test('rejects blank and oversized MCP token names', function (string $name, string $rule) {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(McpAccess::class)
        ->set('name', $name)
        ->call('createToken');

    $component->assertHasErrors(['name' => $rule]);

    expect($user->tokens()->count())->toBe(0);
})->with(['blank' => ['   ', 'required'], 'oversized' => [str_repeat('a', 101), 'max']]);

test('revokes only the current users MCP tokens', function () {
    $user = User::factory()->create();
    $own = $user->createToken('TeamBoard token belonging to the current user');
    $foreign = User::factory()->create()->createToken('TeamBoard token belonging to another user');

    $component = Livewire::actingAs($user)->test(McpAccess::class)
        ->call('revokeToken', $foreign->accessToken->id);

    $component->assertNotFound();
    $this->assertModelExists($foreign->accessToken);

    $component = Livewire::actingAs($user)->test(McpAccess::class)
        ->call('revokeToken', $own->accessToken->id);

    $component->assertHasNoErrors()
        ->assertDontSee('TeamBoard token belonging to the current user');
    $this->assertModelMissing($own->accessToken);
});

test('guests cannot create or revoke MCP tokens', function (string $action, Closure $arguments) {
    $user = User::factory()->create();
    $token = $user->createToken('Guest boundary existing token');
    $page = $this->actingAs($user)->get('/settings/mcp-access');
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    Auth::logout();
    Auth::forgetGuards();

    $response = $this->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['name' => 'Guest forged token'],
            'calls' => [['method' => $action, 'params' => $arguments($token->accessToken->id)]],
        ]],
    ], ['X-Livewire' => 'true']);

    $response->assertUnauthorized();
    $this->assertModelExists($token->accessToken);
    expect($user->tokens()->count())->toBe(1);
})->with([
    'creation' => ['createToken', fn (int $tokenId): array => []],
    'revocation' => ['revokeToken', fn (int $tokenId): array => [$tokenId]],
]);

test('shows creation and last-used times without exposing token hashes', function () {
    $this->travelTo('2026-10-05 10:00:00');
    $user = User::factory()->create();
    $token = $user->createToken('Timestamp display token');
    $token->accessToken->forceFill(['last_used_at' => '2026-10-05 11:30:00'])->save();
    $user->createToken('Never-used display token');

    $response = $this->actingAs($user)->get('/settings/mcp-access');

    $response->assertOk()
        ->assertSee('2026-10-05 10:00 UTC')
        ->assertSee('2026-10-05 11:30 UTC')
        ->assertSee('Never')
        ->assertDontSee($token->accessToken->token);
});
