<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    /**
     * Determine whether the user can view the task. Any member of the
     * task's workspace may view it.
     */
    public function view(User $user, Task $task): bool
    {
        return $user->belongsToWorkspace($task->project->workspace_id);
    }

    /**
     * Determine whether the user can create a task in the project.
     */
    public function create(User $user, Project $project): Response
    {
        return $user->roleIn($project->workspace_id)?->canContribute() === true
            ? Response::allow()
            : Response::deny('Your workspace role cannot create tasks.');
    }

    /**
     * Determine whether the user can edit the task's metadata.
     */
    public function update(User $user, Task $task): Response
    {
        return $user->roleIn($task->project->workspace_id)?->canContribute() === true
            ? Response::allow()
            : Response::deny('Your workspace role cannot update tasks.');
    }

    /**
     * Determine whether the user can assign or unassign the task. Any
     * workspace member, including a viewer, may hold an assignment.
     */
    public function assign(User $user, Task $task): Response
    {
        return $user->roleIn($task->project->workspace_id)?->canContribute() === true
            ? Response::allow()
            : Response::deny('Your workspace role cannot assign tasks.');
    }

    /**
     * Determine whether the user can change the task's status.
     */
    public function move(User $user, Task $task): Response
    {
        return $user->roleIn($task->project->workspace_id)?->canContribute() === true
            ? Response::allow()
            : Response::deny('Your workspace role cannot change task status.');
    }

    /**
     * Determine whether the user can delete the task.
     */
    public function delete(User $user, Task $task): Response
    {
        return $user->roleIn($task->project->workspace_id) === WorkspaceRole::Owner
            ? Response::allow()
            : Response::deny('Your workspace role cannot delete tasks.');
    }
}
