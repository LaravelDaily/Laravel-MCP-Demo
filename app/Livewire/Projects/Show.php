<?php

namespace App\Livewire\Projects;

use App\Actions\Tasks\CreateTask;
use App\Models\Project;
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
     * The project's route key. The record is re-resolved through the
     * workspace context on every request, so a foreign or stale ID is
     * rejected as not found instead of rendering another workspace's data.
     */
    #[Locked]
    public int $projectId;

    public string $title = '';

    public ?string $description = null;

    public string $assignee_id = '';

    public ?string $due_date = null;

    public string $priority = 'medium';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    /**
     * @throws ModelNotFoundException<Project>
     */
    #[Computed]
    public function project(): Project
    {
        return app(WorkspaceContext::class)->project($this->projectId);
    }

    /**
     * @return LengthAwarePaginator<int, Task>
     */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        return $this->project()
            ->tasks()
            ->with('assignee')
            ->orderBy('id')
            ->paginate(10);
    }

    /**
     * Workspace members selectable as assignees for a new task.
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
     * Whether the current user may create tasks in this project.
     */
    #[Computed]
    public function canCreateTasks(): bool
    {
        return auth()->user()->can('create', [Task::class, $this->project()]);
    }

    public function startCreatingTask(): void
    {
        $this->reset(['title', 'description', 'assignee_id', 'due_date']);
        $this->priority = 'medium';

        Flux::modal('task-form')->show();
    }

    /**
     * Create a task through the shared application operation, which
     * re-authorizes and re-checks the selected workspace.
     */
    public function createTask(): void
    {
        app(CreateTask::class)(auth()->user(), $this->project(), [
            'title' => $this->title,
            'description' => $this->description === '' ? null : $this->description,
            'assignee_id' => $this->assignee_id === '' ? null : (int) $this->assignee_id,
            'due_date' => $this->due_date === '' ? null : $this->due_date,
            'priority' => $this->priority,
        ]);

        $this->reset(['title', 'description', 'assignee_id', 'due_date']);
        $this->priority = 'medium';

        Flux::modal('task-form')->close();

        Flux::toast(variant: 'success', text: __('Task created.'));
    }

    public function render(): View
    {
        return view('livewire.projects.show');
    }
}
