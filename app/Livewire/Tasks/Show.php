<?php

namespace App\Livewire\Tasks;

use App\Actions\Tasks\AddComment;
use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\UpdateTaskDetails;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Workspaces\WorkspaceContext;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    /**
     * The task's route key. The record is re-resolved through the
     * workspace context on every request and before every mutation, so a
     * foreign or stale ID is rejected as not found.
     */
    #[Locked]
    public int $taskId;

    public string $title = '';

    public ?string $description = null;

    public string $priority = '';

    public ?string $due_date = null;

    /**
     * Inline status select value.
     */
    public string $status = '';

    /**
     * Inline assignee select value; an empty string means unassigned.
     */
    public string $assignee_id = '';

    public string $body = '';

    public function mount(int $taskId): void
    {
        $this->taskId = $taskId;

        $task = $this->task();

        $this->status = $task->status->value;
        $this->assignee_id = (string) ($task->assignee_id ?? '');
    }

    /**
     * @throws ModelNotFoundException<Task>
     */
    #[Computed]
    public function task(): Task
    {
        return app(WorkspaceContext::class)->task($this->taskId);
    }

    /**
     * @return LengthAwarePaginator<int, Comment>
     */
    #[Computed]
    public function comments(): LengthAwarePaginator
    {
        return $this->task()
            ->comments()
            ->with('author')
            ->orderBy('id')
            ->paginate(10);
    }

    /**
     * Workspace members selectable as assignees.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return app(WorkspaceContext::class)
            ->currentOrFail()
            ->members()
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether the current user may edit tasks in the selected workspace.
     */
    #[Computed]
    public function canContribute(): bool
    {
        return auth()->user()->can('update', $this->task());
    }

    /**
     * Whether the current user may delete this task.
     */
    #[Computed]
    public function canDelete(): bool
    {
        return auth()->user()->can('delete', $this->task());
    }

    public function startEditing(): void
    {
        $task = $this->task();

        $this->title = $task->title;
        $this->description = $task->description;
        $this->priority = $task->priority->value;
        $this->due_date = $task->due_date?->format('Y-m-d');

        Flux::modal('task-form')->show();
    }

    /**
     * Save the edit form's metadata through the shared update operation.
     */
    public function saveDetails(): void
    {
        app(UpdateTaskDetails::class)(auth()->user(), $this->task(), [
            'title' => $this->title,
            'description' => $this->description === '' ? null : $this->description,
            'priority' => $this->priority,
            'due_date' => $this->due_date === '' ? null : $this->due_date,
        ]);

        Flux::modal('task-form')->close();

        Flux::toast(variant: 'success', text: __('Task updated.'));
    }

    /**
     * Apply the inline status select.
     */
    public function changeStatus(): void
    {
        app(ChangeTaskStatus::class)(auth()->user(), $this->task(), $this->status);

        Flux::toast(variant: 'success', text: __('Status updated.'));
    }

    /**
     * Apply the inline assignee select; an empty selection unassigns.
     */
    public function assign(): void
    {
        app(AssignTask::class)(
            auth()->user(),
            $this->task(),
            $this->assignee_id === '' ? null : (int) $this->assignee_id,
        );

        Flux::toast(variant: 'success', text: __('Assignment updated.'));
    }

    /**
     * Add a comment as the authenticated user.
     */
    public function addComment(): void
    {
        app(AddComment::class)(auth()->user(), $this->task(), $this->body);

        $this->reset('body');

        Flux::toast(variant: 'success', text: __('Comment added.'));
    }

    /**
     * Delete the task and return to its project page.
     */
    public function delete(): void
    {
        $task = $this->task();

        app(DeleteTask::class)(auth()->user(), $task);

        $this->redirect(
            route('projects.show', ['projectId' => $task->project_id]),
            navigate: true,
        );
    }

    public function render(): View
    {
        return view('livewire.tasks.show');
    }
}
