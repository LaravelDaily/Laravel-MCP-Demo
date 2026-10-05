<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('MCP access')]
class McpAccess extends Component
{
    public string $name = '';

    protected ?string $plainTextToken = null;

    public function createToken(): void
    {
        $user = $this->user();
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:100']]);

        $this->plainTextToken = $user->createToken($this->name)->plainTextToken;
        $this->reset('name');
    }

    public function revokeToken(int $tokenId): void
    {
        $this->user()->tokens()->findOrFail($tokenId)->delete();
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
        return view('livewire.settings.mcp-access', [
            'plainTextToken' => $this->plainTextToken,
            'tokens' => $this->user()->tokens()->orderBy('id')->get(['id', 'name', 'created_at']),
        ]);
    }
}
