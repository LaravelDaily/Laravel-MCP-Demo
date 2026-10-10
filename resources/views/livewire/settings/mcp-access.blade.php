<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('MCP access')" :subheading="__('AI clients you have connected to TeamBoard')">
        <flux:text>{{ __('Add the TeamBoard MCP URL to your AI client and approve it in the browser. Your workspace memberships and policies determine what a connected client can do. Disconnect a client to end its access.') }}</flux:text>

        <div class="mt-6 space-y-4">
            @forelse ($connections as $connection)
                <div wire:key="connection-{{ $connection['client_id'] }}" class="flex items-center justify-between gap-4">
                    <div>
                        <flux:text>{{ $connection['name'] }}</flux:text>
                        <flux:text size="sm">{{ __('Authorized') }}: {{ $connection['authorized_at']->format('Y-m-d H:i') }} UTC</flux:text>
                    </div>
                    <flux:button wire:click="disconnect('{{ $connection['client_id'] }}')" wire:confirm="{{ __('Disconnect this client?') }}">{{ __('Disconnect') }}</flux:button>
                </div>
            @empty
                <flux:text>{{ __('You have no connected AI clients.') }}</flux:text>
            @endforelse
        </div>
    </x-settings.layout>
</section>
