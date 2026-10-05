<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Dashboard')]
class Dashboard extends Component
{
    use WithPagination;

    /**
     * Resolve the selected workspace, or null when the user has no
     * memberships.
     */
    #[Computed]
    public function workspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    #[Computed]
    public function projectCount(): int
    {
        return app(WorkspaceContext::class)->projects()->count();
    }

    /**
     * Count the workspace's open tasks: todo plus in_progress.
     */
    #[Computed]
    public function openTaskCount(): int
    {
        return app(WorkspaceContext::class)
            ->tasks()
            ->whereIn('status', TaskStatus::openValues())
            ->count();
    }

    /**
     * List the current user's assigned unfinished tasks in the selected
     * workspace.
     *
     * @return LengthAwarePaginator<int, Task>
     */
    #[Computed]
    public function myUnfinishedTasks(): LengthAwarePaginator
    {
        return app(WorkspaceContext::class)
            ->tasks()
            ->where('assignee_id', auth()->id())
            ->whereIn('status', TaskStatus::openValues())
            ->with('project')
            ->orderByDesc('id')
            ->paginate(10);
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }
}
