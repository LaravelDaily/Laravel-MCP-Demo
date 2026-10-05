<?php

use App\Livewire\Settings\McpAccess;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Livewire\Livewire;

test('creates a named MCP token for the authenticated user', function () {
    $user = User::factory()->create();
    $foreign = User::factory()->create()->createToken('Foreign client');

    $component = Livewire::actingAs($user)->test(McpAccess::class)
        ->set('name', '  Claude Code  ')->call('createToken')->assertHasNoErrors()
        ->assertSee('Copy your token now')->assertDontSee('Foreign client');
    preg_match('/\d+\|[a-zA-Z0-9]+/', $component->html(), $matches);
    expect($matches)->toHaveCount(1);
    $plaintext = $matches[0];
    $token = $user->tokens()->sole();
    expect($token->name)->toBe('Claude Code');
    expect($token->token)->toBe(hash('sha256', explode('|', $plaintext, 2)[1]));
    expect($token->expires_at)->toBeNull();
    $component->assertDontSee($token->token)->call('$refresh')->assertDontSee($plaintext)->assertDontSee($token->token);
    $this->get('/settings/mcp-access')->assertOk()->assertDontSee($plaintext)->assertDontSee($token->token)->assertDontSee($foreign->accessToken->token);
});

test('rejects blank and oversized MCP token names', function (string $name, string $rule) {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(McpAccess::class)->set('name', $name)->call('createToken')->assertHasErrors(['name' => $rule]);

    expect($user->tokens()->count())->toBe(0);
})->with(['blank' => ['   ', 'required'], 'oversized' => [str_repeat('a', 101), 'max']]);

test('revokes only the current users MCP tokens', function () {
    $user = User::factory()->create();
    $own = $user->createToken('Own');
    $foreign = User::factory()->create()->createToken('Foreign');

    Livewire::actingAs($user)->test(McpAccess::class)->call('revokeToken', $foreign->accessToken->id)->assertNotFound();
    $this->assertModelExists($foreign->accessToken);
    Livewire::actingAs($user)->test(McpAccess::class)->call('revokeToken', $own->accessToken->id)->assertHasNoErrors()->assertDontSee('Own');
    $this->assertModelMissing($own->accessToken);
});

test('guests cannot create or revoke MCP tokens', function (string $action) {
    $token = User::factory()->create()->createToken('Existing');
    $this->get('/settings/mcp-access')->assertRedirect('/login');

    expect(fn () => app(McpAccess::class)->{$action}(...($action === 'revokeToken' ? [$token->accessToken->id] : [])))
        ->toThrow(AuthenticationException::class);

    $this->assertModelExists($token->accessToken);
})->with(['createToken', 'revokeToken']);
