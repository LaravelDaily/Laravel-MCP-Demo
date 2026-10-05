<div>
    @if ($this->current !== null)
        <div class="px-1 pt-3">
            <flux:dropdown class="w-full" align="start">
                <flux:button variant="ghost" class="w-full justify-between gap-2" size="sm" data-test="workspace-switcher">
                    <span class="flex min-w-0 items-center gap-2">
                        <flux:icon.building-office class="size-4 shrink-0 text-zinc-400" />
                        <span class="truncate text-sm font-medium">{{ $this->current->name }}</span>
                    </span>
                    <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-400" />
                </flux:button>

                <flux:menu class="w-64">
                    @foreach ($this->memberships as $membership)
                        <flux:menu.item wire:click="switchTo({{ $membership->workspace_id }})">
                            <span class="flex w-full items-center justify-between gap-4">
                                <span class="flex min-w-0 items-center gap-2">
                                    @if ($membership->workspace_id === $this->current->id)
                                        <flux:icon.check class="size-4 shrink-0" />
                                    @else
                                        <flux:icon.check class="size-4 shrink-0 opacity-0" />
                                    @endif
                                    <span class="truncate">{{ $membership->workspace->name }}</span>
                                </span>
                                <span class="shrink-0 text-xs uppercase tracking-wide text-zinc-400">{{ $membership->role->value }}</span>
                            </span>
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        </div>
    @endif
</div>
