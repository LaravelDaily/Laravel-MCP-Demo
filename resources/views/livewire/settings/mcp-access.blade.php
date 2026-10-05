<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('MCP access')" :subheading="__('Create and revoke tokens for your AI clients')">
        <flux:text>{{ __('Tokens do not expire. Revoke a token to end its access. Your workspace memberships and policies determine what the client can do.') }}</flux:text>

        <form wire:submit="createToken" class="my-6 space-y-6">
            <flux:input wire:model="name" :label="__('Token name')" required maxlength="100" />
            <flux:button type="submit" variant="primary">{{ __('Create token') }}</flux:button>
        </form>

        @if ($plainTextToken)
            <flux:callout class="my-6">
                <flux:callout.heading>{{ __('Copy your token now') }}</flux:callout.heading>
                <flux:callout.text>{{ __('This token is shown only on creation. Store it securely.') }}</flux:callout.text>
                <flux:textarea readonly :label="__('New token')" :value="$plainTextToken" />
            </flux:callout>
        @endif

        <div class="space-y-4">
            @forelse ($tokens as $token)
                <div wire:key="token-{{ $token->id }}" class="flex items-center justify-between gap-4">
                    <div>
                        <flux:text>{{ $token->name }}</flux:text>
                        <flux:text size="sm">{{ __('Created') }}: {{ $token->created_at->format('Y-m-d H:i') }} UTC</flux:text>
                        <flux:text size="sm">{{ __('Last used') }}: {{ $token->last_used_at ? $token->last_used_at->format('Y-m-d H:i').' UTC' : __('Never') }}</flux:text>
                    </div>
                    <flux:button wire:click="revokeToken({{ $token->id }})" wire:confirm="{{ __('Revoke this token?') }}">{{ __('Revoke') }}</flux:button>
                </div>
            @empty
                <flux:text>{{ __('You have no MCP tokens.') }}</flux:text>
            @endforelse
        </div>
    </x-settings.layout>
</section>
