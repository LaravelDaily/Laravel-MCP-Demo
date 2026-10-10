<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('MCP access')]
class McpAccess extends Component
{
    /**
     * Revoke every access and refresh token the current user granted to one
     * OAuth client. The client must authorize again to reconnect.
     */
    public function disconnect(string $clientId): void
    {
        $tokens = $this->activeTokens()->where('client_id', $clientId);

        if ($tokens->isEmpty()) {
            throw new ModelNotFoundException;
        }

        DB::transaction(function () use ($tokens): void {
            foreach ($tokens as $token) {
                $token->revoke();
                $token->refreshToken?->revoke();
            }
        });
    }

    /**
     * @return Collection<int, Token>
     */
    protected function activeTokens(): Collection
    {
        return $this->user()->tokens()
            ->where('revoked', false)
            ->with(['client', 'refreshToken'])
            ->get();
    }

    protected function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    public function render(): View
    {
        $connections = $this->activeTokens()
            ->groupBy('client_id')
            ->map(fn (Collection $tokens): array => [
                'client_id' => $tokens->first()->client_id,
                'name' => $tokens->first()->client->name,
                'authorized_at' => $tokens->min('created_at'),
            ])
            ->sortBy('authorized_at')
            ->values();

        return view('livewire.settings.mcp-access', [
            'connections' => $connections,
        ]);
    }
}
