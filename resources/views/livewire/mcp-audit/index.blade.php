<div class="w-full space-y-6">
    @if ($this->workspace === null)
        <x-no-workspace />
    @else
        <div>
            <flux:heading level="1">{{ __('MCP audit log') }}</flux:heading>
            <flux:subheading>{{ __('Successful MCP task changes in :workspace, newest first', ['workspace' => $this->workspace->name]) }}</flux:subheading>
        </div>

        <flux:table :paginate="$this->entries">
            <flux:table.columns>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column>{{ __('Tool') }}</flux:table.column>
                <flux:table.column>{{ __('Actor') }}</flux:table.column>
                <flux:table.column>{{ __('Entity') }}</flux:table.column>
                <flux:table.column>{{ __('Summary') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->entries as $entry)
                    <flux:table.row :key="$entry->id" data-test="audit-entry">
                        <flux:table.cell class="whitespace-nowrap">{{ $entry->created_at?->format('Y-m-d H:i:s') }} UTC</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $entry->tool }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $entry->actor->name }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $entry->entity_type === null ? '—' : $entry->entity_type.' #'.$entry->entity_id }}</flux:table.cell>
                        <flux:table.cell class="max-w-lg">
                            <p class="whitespace-normal">{{ $entry->summary }}</p>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <flux:text>{{ __('No MCP changes have been recorded in this workspace yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif
</div>
