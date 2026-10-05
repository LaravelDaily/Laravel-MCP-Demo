<div class="w-full space-y-6">
    <div>
        <flux:link href="{{ route('projects.show', ['projectId' => $this->task->project_id]) }}" wire:navigate icon-trailing="arrow-left" class="text-sm">
            {{ $this->task->project->name }}
        </flux:link>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <flux:heading level="1" data-test="task-title">{{ $this->task->title }}</flux:heading>
            <x-task-status-badge :status="$this->task->status" data-test="task-status" />
            <flux:badge color="zinc" inset data-test="task-priority">{{ $this->task->priority->value }}</flux:badge>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 lg:col-span-2">
            <flux:heading level="2">{{ __('Description') }}</flux:heading>

            <flux:text class="mt-2 whitespace-pre-line">
                {{ $this->task->description ?? __('No description yet.') }}
            </flux:text>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading level="2">{{ __('Details') }}</flux:heading>

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-zinc-500">{{ __('Status') }}</dt>
                        <dd>
                            @if ($this->canContribute)
                                <flux:select wire:model="status" wire:change="changeStatus" size="sm" class="w-40">
                                    @foreach (\App\Enums\TaskStatus::cases() as $case)
                                        <flux:select.option value="{{ $case->value }}">{{ $case->value }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                {{ $this->task->status->value }}
                            @endif
                        </dd>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-zinc-500">{{ __('Assignee') }}</dt>
                        <dd data-test="assignee">
                            @if ($this->canContribute)
                                <flux:select wire:model="assignee_id" wire:change="assign" size="sm" class="w-40">
                                    <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                                    @foreach ($this->members as $member)
                                        <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                {{ $this->task->assignee?->name ?? __('Unassigned') }}
                            @endif
                        </dd>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-zinc-500">{{ __('Priority') }}</dt>
                        <dd data-test="priority">{{ $this->task->priority->value }}</dd>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-zinc-500">{{ __('Due date') }}</dt>
                        <dd data-test="due-date">{{ $this->task->due_date?->format('Y-m-d') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="flex gap-2">
                @if ($this->canContribute)
                    <flux:button variant="outline" wire:click="startEditing" data-test="edit-task">
                        {{ __('Edit task') }}
                    </flux:button>
                @endif

                @if ($this->canDelete)
                    <flux:button variant="ghost" class="text-red-500" wire:click="$fluxModal('task-delete').show()" data-test="delete-task">
                        {{ __('Delete task') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading level="2">{{ __('Comments') }}</flux:heading>

        @if ($this->comments->isEmpty())
            <flux:text class="mt-3">{{ __('No comments yet.') }}</flux:text>
        @else
            <ul class="mt-3 space-y-4">
                @foreach ($this->comments as $comment)
                    <li class="flex gap-3" data-test="comment">
                        <flux:avatar :name="$comment->author->name" :initials="$comment->author->initials()" class="shrink-0" />

                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ $comment->author->name }}</p>
                            <flux:text size="sm" class="whitespace-pre-line">{{ $comment->body }}</flux:text>
                        </div>
                    </li>
                @endforeach
            </ul>

            {{ $this->comments->links() }}
        @endif

        @if ($this->canContribute)
            <form wire:submit="addComment" class="mt-6 space-y-3">
                <flux:textarea wire:model="body" :label="__('Add a comment')" rows="3" data-test="comment-body" />

                <div class="flex justify-end">
                    <flux:button variant="primary" type="submit">{{ __('Comment') }}</flux:button>
                </div>
            </form>
        @endif
    </div>

    <flux:modal name="task-form" class="w-full max-w-lg">
        <form wire:submit="saveDetails" class="space-y-6">
            <flux:heading>{{ __('Edit task') }}</flux:heading>

            <flux:input wire:model="title" :label="__('Title')" maxlength="255" />

            <flux:textarea wire:model="description" :label="__('Description')" rows="4" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="priority" :label="__('Priority')">
                    @foreach (\App\Enums\TaskPriority::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->value }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="due_date" :label="__('Due date')" type="date" />
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" flux:modal.close>{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="task-delete" variant="danger" class="w-full max-w-sm">
        <form wire:submit="delete" class="space-y-6">
            <flux:heading>{{ __('Delete task') }}</flux:heading>

            <flux:text>{{ __('The task and its comments will be deleted. This action cannot be undone.') }}</flux:text>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" flux:modal.close>{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" type="submit">{{ __('Delete task') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
