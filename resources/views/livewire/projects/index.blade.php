<div class="w-full space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading level="1">{{ __('Projects') }}</flux:heading>
            <flux:subheading>{{ __('Projects in :workspace', ['workspace' => $this->workspace->name]) }}</flux:subheading>
        </div>

        @if ($this->canManageProjects)
            <flux:button variant="primary" icon="plus" wire:click="startCreating" data-test="create-project">
                {{ __('New project') }}
            </flux:button>
        @endif
    </div>

    <flux:table :paginate="$this->projects">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Tasks') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->projects as $project)
                <flux:table.row>
                    <flux:table.cell class="font-medium">
                        <flux:link href="{{ route('projects.show', ['projectId' => $project->id]) }}" wire:navigate>{{ $project->name }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $project->tasks_count }}</flux:table.cell>
                    <flux:table.cell class="max-w-lg">
                        <p class="truncate text-zinc-500">{{ $project->description }}</p>
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        @if ($this->canManageProjects)
                            <flux:button size="xs" variant="ghost" wire:click="startEditing({{ $project->id }})">
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button size="xs" variant="ghost" class="text-red-500" wire:click="confirmDelete({{ $project->id }})">
                                {{ __('Delete') }}
                            </flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">
                        <flux:text>{{ __('No projects yet.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="project-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading>{{ $editingId === null ? __('New project') : __('Edit project') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" maxlength="255" />

            <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

            <flux:textarea wire:model="instructions" :label="__('AI instructions')" rows="3" />

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" flux:modal.close>{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="project-delete" variant="danger" class="w-full max-w-sm">
        <form wire:submit="delete" class="space-y-6">
            <flux:heading>{{ __('Delete project') }}</flux:heading>

            <flux:text>
                {{ __('The project, its tasks, and their comments will be deleted. This action cannot be undone.') }}
            </flux:text>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" flux:modal.close>{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" type="submit">{{ __('Delete project') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
