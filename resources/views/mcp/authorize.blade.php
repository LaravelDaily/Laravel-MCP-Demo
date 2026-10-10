<x-layouts::auth :title="__('Authorize :name', ['name' => $client->name])">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Authorize :name', ['name' => $client->name])"
            :description="__('This application wants to use TeamBoard on your behalf.')"
        />

        <flux:callout icon="information-circle">
            <flux:callout.text>
                {{ __('It will act as you in every workspace you belong to, with the same role and permissions you have in TeamBoard. Revoke it at any time in Settings → MCP access.') }}
            </flux:callout.text>
        </flux:callout>

        <div class="space-y-1">
            <flux:text size="sm">{{ __('Logged in as') }}</flux:text>
            <flux:text variant="strong">{{ $user->email }}</flux:text>
        </div>

        <div class="space-y-1">
            <flux:text size="sm">{{ __('After you decide, you will be sent back to') }}</flux:text>
            @foreach ($client->redirect_uris as $redirectUri)
                <flux:text variant="strong">{{ parse_url($redirectUri, PHP_URL_SCHEME).'://'.parse_url($redirectUri, PHP_URL_HOST) }}</flux:text>
            @endforeach
        </div>

        <div class="flex gap-3">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" class="w-full">{{ __('Deny') }}</flux:button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" variant="primary" class="w-full">{{ __('Approve') }}</flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
