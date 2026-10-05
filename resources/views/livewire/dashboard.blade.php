<div class="w-full">
    @if ($this->workspace === null)
        <div class="flex h-full w-full flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-zinc-200 p-12 text-center dark:border-zinc-700">
            <flux:icon.folder-open class="size-10 text-zinc-400" />
            <flux:heading>{{ __('No workspace yet') }}</flux:heading>
            <flux:text class="max-w-sm">
                {{ __('You are not a member of any workspace yet. Ask a workspace owner to add you to one.') }}
            </flux:text>
        </div>
    @else
        <div class="space-y-6">
            <div>
                <flux:heading level="1" data-test="workspace-name">{{ $this->workspace->name }}</flux:heading>
                <flux:subheading>{{ __('Overview of your selected workspace') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading>{{ $this->projectCount }} {{ Str::plural(__('project'), $this->projectCount) }}</flux:heading>
                    <flux:text class="text-zinc-500">{{ __('in this workspace') }}</flux:text>
                </div>

                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading data-test="open-tasks">{{ $this->openTaskCount }} {{ Str::plural(__('open task'), $this->openTaskCount) }}</flux:heading>
                    <flux:text class="text-zinc-500">{{ __('todo or in progress') }}</flux:text>
                </div>

                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading data-test="my-tasks">{{ $this->myUnfinishedTasks->total() }} {{ Str::plural(__('task'), $this->myUnfinishedTasks->total()) }}</flux:heading>
                    <flux:text class="text-zinc-500">{{ __('unfinished and assigned to you') }}</flux:text>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading level="2">{{ __('My unfinished tasks') }}</flux:heading>

                @if ($this->myUnfinishedTasks->isEmpty())
                    <flux:text class="mt-3">{{ __('Nothing is assigned to you right now.') }}</flux:text>
                @else
                    <ul class="mt-3 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->myUnfinishedTasks as $task)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ $task->title }}</p>
                                    <flux:text size="sm" class="truncate">{{ $task->project->name }}</flux:text>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <flux:badge color="{{ $task->status->color() }}" inset>{{ $task->status->value }}</flux:badge>

                                    @if ($task->due_date !== null)
                                        <flux:text size="sm" class="whitespace-nowrap">{{ $task->due_date->format('Y-m-d') }}</flux:text>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    {{ $this->myUnfinishedTasks->links() }}
                @endif
            </div>
        </div>
    @endif
</div>
