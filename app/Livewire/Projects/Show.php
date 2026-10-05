<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Task;
use App\Workspaces\WorkspaceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    /**
     * The project's route key. The record is re-resolved through the
     * workspace context on every request, so a foreign or stale ID is
     * rejected as not found instead of rendering another workspace's data.
     */
    public int $projectId;

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

    public function render(): View
    {
        return view('livewire.projects.show');
    }
}
