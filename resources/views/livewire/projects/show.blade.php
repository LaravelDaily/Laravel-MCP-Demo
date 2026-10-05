<div class="w-full space-y-6">
    <div>
        <flux:link href="{{ route('projects.index') }}" wire:navigate icon-trailing="arrow-left" class="text-sm">
            {{ __('Projects') }}
        </flux:link>

        <flux:heading level="1" class="mt-2" data-test="project-name">{{ $this->project->name }}</flux:heading>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 lg:col-span-2">
            <flux:heading level="2">{{ __('Description') }}</flux:heading>

            <flux:text class="mt-2 whitespace-pre-line">
                {{ $this->project->description ?? __('No description yet.') }}
            </flux:text>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading level="2">{{ __('AI instructions') }}</flux:heading>

            <flux:text class="mt-2 whitespace-pre-line">
                {{ $this->project->instructions ?? __('No instructions yet.') }}
            </flux:text>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading level="2">{{ __('Tasks') }}</flux:heading>

        @if ($this->tasks->isEmpty())
            <flux:text class="mt-3">{{ __('No tasks yet.') }}</flux:text>
        @else
            <div class="mt-3 overflow-x-auto">
                <flux:table :paginate="$this->tasks">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Title') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Priority') }}</flux:table.column>
                        <flux:table.column>{{ __('Assignee') }}</flux:table.column>
                        <flux:table.column>{{ __('Due date') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->tasks as $task)
                            <flux:table.row>
                                <flux:table.cell class="font-medium" data-test="task-title">{{ $task->title }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="{{ $task->status->color() }}" inset>{{ $task->status->value }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>{{ $task->priority->value }}</flux:table.cell>
                                <flux:table.cell>{{ $task->assignee?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $task->due_date?->format('Y-m-d') ?? '—' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
</div>
