<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use App\Workspaces\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Second authorization boundary behind the scoped lookups: application
 * operations re-verify that the records they receive belong to the
 * selected workspace before mutating them.
 */
trait RequiresSelectedWorkspace
{
    /**
     * @throws ModelNotFoundException<Project>
     */
    protected function projectInSelectedWorkspace(Project $project): Project
    {
        if ($project->workspace_id !== $this->selectedWorkspaceId()) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
        }

        return $project;
    }

    /**
     * @throws ModelNotFoundException<Task>
     */
    protected function taskInSelectedWorkspace(Task $task): Task
    {
        if ($task->project->workspace_id !== $this->selectedWorkspaceId()) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$task->getKey()]);
        }

        return $task;
    }

    /**
     * The workspace this request operates in, as resolved by the
     * workspace context.
     */
    protected function selectedWorkspaceId(): int
    {
        return app(WorkspaceContext::class)->currentOrFail()->id;
    }
}
